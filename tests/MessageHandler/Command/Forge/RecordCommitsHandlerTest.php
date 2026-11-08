<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Forge;

use App\Entity\CodeReference;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Forge\RecordCommits;
use App\Message\CommandBus;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecordCommitsHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private ForgeTestSupport $forge;
    private InMemoryTransport $async;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($entityManager, $this->commands);
        $this->forge = new ForgeTestSupport($entityManager);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
    }

    /**
     * @return list<JournalEntry>
     */
    private function pushes(Issue $issue): array
    {
        return array_values(array_filter($this->forge->journal($issue), static fn ($entry): bool => JournalEntryType::CommitsPushed === $entry->getType()));
    }

    public function testAPushIsRecordedAsOnePrivateEntryWithoutAuthor(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $repository = $this->forge->link($this->fixtures->access->shop);
        $this->async->reset();

        self::mockTime('2026-03-04 09:02:00');
        $new = $this->commands->dispatch(new RecordCommits((int) $repository->getId(), $issue->getKey(), 'main', 'https://gitlab.test/maison-dupont/shop/-/tree/main', [
            ForgeTestSupport::commit('a1b2c3d4e5f6'),
            ForgeTestSupport::commit('e4f5a6b7c8d9', 'test(export): 8 000 orders'),
            ForgeTestSupport::commit('a1b2c3d4e5f6', 'the same sha twice in one push'),
        ]));

        self::assertCount(2, $new, 'a sha repeated in the payload counts once');
        self::assertSame('a1b2c3d4', $new[0]->getShortSha());
        self::assertSame('main', $new[0]->getBranch());
        self::assertSame('maison-dupont/shop', $new[0]->getRepositoryPath());
        self::assertSame('2026-03-04 09:02:00', $issue->getUpdatedAt()->format('Y-m-d H:i:s'));

        $entries = $this->pushes($issue);
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getAuthor());
        self::assertTrue($entries[0]->isPrivate());
        self::assertSame('main', $entries[0]->getData()['branch']);
        self::assertSame('gitlab', $entries[0]->getData()['forge']);
        self::assertSame(['a1b2c3d4', 'e4f5a6b7'], array_column($entries[0]->getData()['commits'], 'sha'));
        self::assertSame('Nadia Benali', $entries[0]->getData()['commits'][0]['author']);
        self::assertSame([], $this->async->getSent(), 'a forge delivery notifies nobody');
    }

    public function testACommitSeenAgainIsNotRecordedTwice(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $repository = $this->forge->link($this->fixtures->access->shop);
        $push = static fn (string $branch, string ...$shas): RecordCommits => new RecordCommits((int) $repository->getId(), $issue->getKey(), $branch, 'https://gitlab.test/maison-dupont/shop/-/tree/'.$branch, array_values(array_map(ForgeTestSupport::commit(...), $shas)));

        $this->commands->dispatch($push('main', 'a1b2c3d4e5f6'));
        self::assertSame([], $this->commands->dispatch($push('main', 'a1b2c3d4e5f6')), 'the same push again writes nothing');
        $second = $this->commands->dispatch($push('release/2.4', 'a1b2c3d4e5f6', '9c8d7e6f5a4b'));

        self::assertSame(['9c8d7e6f5a4b'], array_map(static fn (CodeReference $commit): string => $commit->getExternalId(), $second));
        self::assertSame([['a1b2c3d4'], ['9c8d7e6f']], array_map(static fn ($entry): array => array_column($entry->getData()['commits'], 'sha'), $this->pushes($issue)));
        self::assertCount(2, self::getContainer()->get(EntityManagerInterface::class)->getRepository(CodeReference::class)->findBy(['issue' => $issue]));
    }

    public function testOnlyTheIssuesOfTheRepositoryProjectAreWritten(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $elsewhere = $this->forge->link($this->fixtures->access->intranet, 'maison-dupont/intranet');
        $commits = [ForgeTestSupport::commit('a1b2c3d4e5f6')];
        $push = static fn (int $repositoryId, string $key): RecordCommits => new RecordCommits($repositoryId, $key, 'main', 'https://gitlab.test/x/-/tree/main', $commits);

        self::assertSame([], $this->commands->dispatch($push((int) $elsewhere->getId(), $issue->getKey())), 'a key of another project');
        self::assertSame([], $this->commands->dispatch($push((int) $elsewhere->getId(), 'MD-INT-999')), 'an unknown key');
        self::assertSame([], $this->commands->dispatch($push(999999, $issue->getKey())), 'an unlinked repository');

        $repository = $this->forge->link($this->fixtures->access->shop);
        $this->fixtures->access->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame([], $this->commands->dispatch($push((int) $repository->getId(), $issue->getKey())), 'an archived project');

        self::assertCount(1, $this->forge->journal($issue), 'only the creation');
    }
}

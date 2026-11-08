<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Forge;

use App\Entity\CodeRepository;
use App\Entity\Enum\MergeRequestState;
use App\Entity\Issue;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Message\CommandBus;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecordMergeRequestHandlerTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private ForgeTestSupport $forge;
    private Issue $issue;
    private CodeRepository $repository;
    private InMemoryTransport $async;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($entityManager, $this->commands);
        $this->forge = new ForgeTestSupport($entityManager);
        $this->issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->repository = $this->forge->link($this->fixtures->access->shop);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        $this->async->reset();
    }

    private function deliver(MergeRequestState $state, string $title = 'fix(export): paginate the CSV export'): RecordMergeRequest
    {
        return new RecordMergeRequest((int) $this->repository->getId(), $this->issue->getKey(), ForgeTestSupport::mergeRequest($state, title: $title));
    }

    /**
     * @return list<string>
     */
    private function mergeRequestEntries(): array
    {
        return array_values(array_map(
            static fn ($entry): string => $entry->getType()->value,
            array_filter($this->forge->journal($this->issue), static fn ($entry): bool => $entry->getType()->isCode()),
        ));
    }

    public function testAMergeRequestIsJournaledOnEachChangeOfState(): void
    {
        $opened = $this->commands->dispatch($this->deliver(MergeRequestState::Opened));
        self::assertNotNull($opened);
        self::assertSame(MergeRequestState::Opened, $opened->getState());
        self::assertSame('431', $opened->getExternalId());
        self::assertSame('fix/MD-SPT-4-export', $opened->getBranch());

        $same = $this->commands->dispatch($this->deliver(MergeRequestState::Opened, 'fix(export): paginate the CSV export (reviewed)'));
        self::assertSame($opened->getId(), $same?->getId());
        self::assertSame('fix(export): paginate the CSV export (reviewed)', $opened->getTitle(), 'the title follows, without a journal line');

        $this->commands->dispatch($this->deliver(MergeRequestState::Merged));
        self::assertSame(MergeRequestState::Merged, $opened->getState());

        self::assertSame(['merge_request_opened', 'merge_request_merged'], $this->mergeRequestEntries());
        $entries = array_values(array_filter($this->forge->journal($this->issue), static fn ($entry): bool => $entry->getType()->isCode()));
        self::assertNull($entries[1]->getAuthor());
        self::assertTrue($entries[1]->isPrivate());
        self::assertSame('431', $entries[1]->getData()['number']);
        self::assertSame('merged', $entries[1]->getData()['state']);
        self::assertSame([], $this->async->getSent(), 'a forge delivery notifies nobody');
    }

    public function testAMergedRequestNeverComesBack(): void
    {
        $this->commands->dispatch($this->deliver(MergeRequestState::Merged));
        $this->commands->dispatch($this->deliver(MergeRequestState::Opened, 'a late or replayed delivery'));
        $this->commands->dispatch($this->deliver(MergeRequestState::Closed));

        $reference = $this->commands->dispatch($this->deliver(MergeRequestState::Merged));
        self::assertSame(MergeRequestState::Merged, $reference?->getState());
        self::assertSame('fix(export): paginate the CSV export', $reference->getTitle(), 'not even its title');
        self::assertSame(['merge_request_merged'], $this->mergeRequestEntries());
    }

    public function testAClosedMergeRequestOpenedAgainIsReopened(): void
    {
        $this->commands->dispatch($this->deliver(MergeRequestState::Closed));
        $this->commands->dispatch($this->deliver(MergeRequestState::Opened));

        self::assertSame(['merge_request_closed', 'merge_request_reopened'], $this->mergeRequestEntries());
    }

    public function testAKeyOfAnotherProjectIsIgnored(): void
    {
        $elsewhere = $this->forge->link($this->fixtures->access->intranet, 'maison-dupont/intranet');

        self::assertNull($this->commands->dispatch(new RecordMergeRequest((int) $elsewhere->getId(), $this->issue->getKey(), ForgeTestSupport::mergeRequest(MergeRequestState::Opened, 7))));
        self::assertSame([], $this->mergeRequestEntries());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Tag;

use App\Entity\Enum\TagColour;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Tag;
use App\Entity\User;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\DeleteIssue;
use App\Message\Command\Issue\TagIssue;
use App\Message\Command\Issue\UntagIssue;
use App\Message\Command\Tag\CreateTag;
use App\Message\Command\Tag\DeleteTag;
use App\Message\Command\Tag\UpdateTag;
use App\Message\CommandBus;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Tag\TagException;
use App\Repository\IssueTagRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * CreateTag, UpdateTag, DeleteTag, then TagIssue, UntagIssue and the tags
 * of CreateIssue. Test permissions: project.configure for project
 * managers only, issue.set_tags for everyone.
 */
final class TagHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;
    private IssueTagRepository $issueTags;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
        $this->issueTags = self::getContainer()->get(IssueTagRepository::class);
    }

    public function testAManagerCreatesATag(): void
    {
        $tag = $this->create('  Back office ', TagColour::Teal);

        self::assertSame('Back office', $tag->getName());
        self::assertSame(TagColour::Teal, $tag->getColour());
        self::assertSame($this->fixtures->access->shop, $tag->getProject());
    }

    public function testANameIsUniqueWithinItsProjectWhateverItsCase(): void
    {
        $this->create('Back office');
        $this->commands->dispatch(new CreateTag((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->intranet->getId(), 'Back office', TagColour::Red));

        $this->create('Équipe');
        try {
            $this->create('équipe');
            self::fail('An accented letter is the same in both cases.');
        } catch (TagException) {
        }

        $this->expectException(TagException::class);
        $this->create('back OFFICE');
    }

    public function testATagIsRenamedAndPaintedAndKeepsItsNameUnique(): void
    {
        $this->create('Back office');
        $front = $this->create('Front');
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new UpdateTag($manager, (int) $front->getId(), colour: TagColour::Rose));
        self::assertSame('Front', $front->getName(), 'a property left null keeps its value');
        self::assertSame(TagColour::Rose, $front->getColour());

        $this->commands->dispatch(new UpdateTag($manager, (int) $front->getId(), name: 'front'));
        self::assertSame('front', $front->getName(), 'its own name in another case is not taken');

        $this->expectException(TagException::class);
        $this->commands->dispatch(new UpdateTag($manager, (int) $front->getId(), name: 'Back office'));
    }

    public function testOnlyWhoConfiguresTheProjectManagesItsTags(): void
    {
        $tag = $this->create('Back office');
        $attempts = [
            new CreateTag((int) $this->fixtures->developer->getId(), (int) $this->fixtures->access->shop->getId(), 'Front', TagColour::Blue),
            new UpdateTag((int) $this->fixtures->developer->getId(), (int) $tag->getId(), name: 'Admin'),
            new DeleteTag((int) $this->fixtures->client->getId(), (int) $tag->getId()),
        ];
        foreach ($attempts as $attempt) {
            try {
                $this->commands->dispatch($attempt);
                self::fail($attempt::class.' is for who configures the project.');
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame('Back office', $tag->getName());
    }

    public function testAnIssueCarriesSeveralTagsAndItsHistorySaysSo(): void
    {
        $back = $this->create('Back office');
        $front = $this->create('Front');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $client = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new TagIssue($client, $issue->getKey(), (int) $front->getId()));
        $this->commands->dispatch(new TagIssue($client, $issue->getKey(), (int) $back->getId()));
        $this->commands->dispatch(new TagIssue($client, $issue->getKey(), (int) $back->getId()));

        self::assertSame(['Back office', 'Front'], $this->names($issue), 'by name, each once');
        self::assertSame(['field' => 'tag_added', 'from' => null, 'to' => 'Back office'], $this->lastEntry($issue)->getData());
        self::assertCount(3, $this->entries($issue), 'created, then one entry per tag: carried already, nothing is told');

        $this->commands->dispatch(new UntagIssue($client, $issue->getKey(), (int) $front->getId()));
        $this->commands->dispatch(new UntagIssue($client, $issue->getKey(), (int) $front->getId()));

        self::assertSame(['Back office'], $this->names($issue));
        self::assertSame(['field' => 'tag_removed', 'from' => 'Front', 'to' => null], $this->lastEntry($issue)->getData());
        self::assertCount(4, $this->entries($issue));
    }

    public function testAnIssueOnlyCarriesTheTagsOfItsProject(): void
    {
        $elsewhere = $this->commands->dispatch(new CreateTag((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->intranet->getId(), 'Back office', TagColour::Red));
        self::assertInstanceOf(Tag::class, $elsewhere);
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(TagException::class);
        $this->commands->dispatch(new TagIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), (int) $elsewhere->getId()));
    }

    public function testOnlyAnIssueOneSeesIsTagged(): void
    {
        $tag = $this->create('Back office');
        $internal = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks);
        $outsider = $this->fixtures->access->user('sophie@elsewhere.test');
        $public = $this->fixtures->createIssue($this->fixtures->client);

        foreach ([[$this->fixtures->client, $internal], [$outsider, $public]] as [$actor, $issue]) {
            try {
                $this->commands->dispatch(new TagIssue((int) $actor->getId(), $issue->getKey(), (int) $tag->getId()));
                self::fail('An issue one cannot see is not tagged.');
            } catch (IssueException) {
            }
        }
        self::assertSame([], $this->names($internal));
        self::assertSame([], $this->names($public));
    }

    public function testAnIssueIsCreatedWithItsTags(): void
    {
        $back = $this->create('Back office');
        $front = $this->create('Front');

        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->client->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Empty CSV export',
            description: 'The downloaded file is empty.',
            fields: ['environment' => 'prod'],
            tagIds: [(int) $front->getId(), (int) $back->getId(), (int) $front->getId()],
        ));
        self::assertInstanceOf(Issue::class, $issue);

        self::assertSame(['Back office', 'Front'], $this->names($issue));
        self::assertCount(1, $this->entries($issue), 'created with them: nothing more to tell');
    }

    public function testADeletedTagLeavesItsIssuesWithoutAWord(): void
    {
        $back = $this->create('Back office');
        $front = $this->create('Front');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();
        $this->commands->dispatch(new TagIssue($manager, $issue->getKey(), (int) $back->getId()));
        $this->commands->dispatch(new TagIssue($manager, $issue->getKey(), (int) $front->getId()));
        $entries = \count($this->entries($issue));
        $backId = (int) $back->getId();

        $this->commands->dispatch(new DeleteTag($manager, $backId));

        self::assertNull($this->entityManager->getRepository(Tag::class)->find($backId));
        self::assertSame(['Front'], $this->names($issue));
        self::assertCount($entries, $this->entries($issue), 'the history keeps what it said and gains nothing');
    }

    public function testADeletedIssueTakesWhatTiedItToItsTags(): void
    {
        $tag = $this->create('Back office');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();
        $this->commands->dispatch(new TagIssue($manager, $issue->getKey(), (int) $tag->getId()));

        $this->commands->dispatch(new DeleteIssue($manager, $issue->getKey()));

        self::assertSame(0, $this->issueTags->count(['tag' => $tag]));
        self::assertNotNull($this->entityManager->getRepository(Tag::class)->find($tag->getId()), 'the tag stays');
    }

    public function testAListFiltersByTagAndByNone(): void
    {
        $back = $this->create('Back office');
        $front = $this->create('Front');
        $manager = (int) $this->fixtures->manager->getId();
        $both = $this->fixtures->createIssue($this->fixtures->client, title: 'Both');
        $onlyFront = $this->fixtures->createIssue($this->fixtures->client, title: 'Front only');
        $none = $this->fixtures->createIssue($this->fixtures->client, title: 'None');
        $hidden = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks, 'Hidden');
        foreach ([[$both, $back], [$both, $front], [$onlyFront, $front], [$hidden, $back]] as [$issue, $tag]) {
            $this->commands->dispatch(new TagIssue($manager, $issue->getKey(), (int) $tag->getId()));
        }
        $projectId = (int) $this->fixtures->access->shop->getId();
        $titles = fn (array $tags, ?User $viewer = null): array => array_map(
            static fn (Issue $issue): string => $issue->getTitle(),
            self::queryBus()->ask(new SearchIssues($viewer ?? $this->fixtures->manager, projectId: $projectId, tag: array_values($tags), sort: IssueSort::Title))->items,
        );

        self::assertSame(['Both', 'Hidden'], $titles([(int) $back->getId()]));
        self::assertSame(['Both'], $titles([(int) $back->getId()], $this->fixtures->client), 'the filter shows nothing the viewer does not see');
        self::assertSame(['Both', 'Front only', 'Hidden'], $titles([(int) $back->getId(), (int) $front->getId()]), 'one of the tags is enough, and an issue is listed once');
        self::assertSame(['None'], $titles([0]));
        self::assertSame(['Both', 'Front only', 'None'], $titles([0, (int) $front->getId()], $this->fixtures->client));
    }

    private function create(string $name, TagColour $colour = TagColour::Blue): Tag
    {
        $tag = $this->commands->dispatch(new CreateTag((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->shop->getId(), $name, $colour));
        self::assertInstanceOf(Tag::class, $tag);

        return $tag;
    }

    /**
     * @return list<string>
     */
    private function names(Issue $issue): array
    {
        return array_map(static fn (Tag $tag): string => $tag->getName(), $this->issueTags->tagsOf($issue));
    }

    /**
     * @return list<JournalEntry>
     */
    private function entries(Issue $issue): array
    {
        return $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $issue], ['id' => 'ASC']);
    }

    private function lastEntry(Issue $issue): JournalEntry
    {
        $entries = $this->entries($issue);

        return end($entries) ?: self::fail('The issue has no history.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\CreateIssue;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CreateIssueHandlerTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    public function testIssuesAreNumberedPerProjectAndStartOnTheInitialPlace(): void
    {
        $first = $this->fixtures->createIssue($this->fixtures->client);
        $second = $this->fixtures->createIssue($this->fixtures->client, title: 'Second');

        self::assertSame('MD-SPT-1', $first->getKey());
        self::assertSame('MD-SPT-2', $second->getKey());
        self::assertSame('new', $first->getPlace());
        self::assertSame(Criticality::Normal, $first->getCriticality());
        self::assertSame('Empty CSV export', $first->getTitle());
        self::assertSame(['environment' => 'prod'], $first->getFieldValues());
        self::assertFalse($first->isPrivate());
        self::assertNotNull($first->getId(), 'flushed by the transaction middleware');

        $entries = $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $first]);
        self::assertCount(1, $entries);
        self::assertSame(JournalEntryType::Created, $entries[0]->getType());
        self::assertSame($this->fixtures->client->getId(), $entries[0]->getAuthor()?->getId());
    }

    public function testAnInternalCanQualifyAtCreation(): void
    {
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->manager->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Endless spinner',
            criticality: Criticality::Blocking,
            assigneeId: $this->fixtures->client->getId(),
            private: true,
            fields: ['environment' => 'prod', 'url' => 'https://shop.test/export'],
        ));

        self::assertSame(Criticality::Blocking, $issue->getCriticality());
        self::assertSame($this->fixtures->client->getId(), $issue->getAssignee()?->getId(), 'a client can be assigned');
        self::assertTrue($issue->isPrivate());
    }

    public function testOnlyAMemberOfTheProjectMayBeAssignedAtCreation(): void
    {
        $outsider = $this->fixtures->access->user('paul@other.test');

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('holds no role');
        $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->manager->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'For a stranger',
            assigneeId: $outsider->getId(),
            fields: ['environment' => 'prod'],
        ));
    }

    public function testIssuesOfAPrivateTrackerArePrivateAndClosedToExternals(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->developer, $this->fixtures->tasks);
        self::assertTrue($issue->isPrivate());

        $this->expectException(AccessDeniedException::class);
        $this->fixtures->createIssue($this->fixtures->client, $this->fixtures->tasks);
    }

    public function testAClientMayNeitherAssignNorHideNorCreateWithoutAccess(): void
    {
        $outsider = $this->fixtures->access->user('paul@other.test');
        try {
            $this->fixtures->createIssue($outsider);
            self::fail('No membership');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('make issues private');
        $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->client->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Hidden',
            private: true,
            fields: ['environment' => 'prod'],
        ));
    }

    public function testFieldsMustBelongToTheWorkflowAndRequiredOnesMustBeFilled(): void
    {
        try {
            $this->fixtures->createIssue($this->fixtures->client, fields: ['environment' => 'prod', 'ghost' => 1]);
            self::fail('Unknown field');
        } catch (IssueException $e) {
            self::assertStringContainsString('"ghost"', $e->getMessage());
        }

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('"environment" is required');
        $this->fixtures->createIssue($this->fixtures->client, fields: ['url' => 'https://shop.test']);
    }

    public function testAnArchivedProjectTakesNoIssue(): void
    {
        $this->fixtures->access->shop->archive();
        $this->entityManager->flush();

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('archived');
        $this->fixtures->createIssue($this->fixtures->client);
    }

    public function testTheCommandIsValidatedBeforeHandling(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->fixtures->createIssue($this->fixtures->client, title: '   ');
    }

    public function testHandlerExceptionsAreNotWrapped(): void
    {
        try {
            $this->fixtures->createIssue($this->fixtures->client, fields: []);
        } catch (HandlerFailedException) {
            self::fail('HandleTrait must unwrap the handler exception');
        } catch (IssueException) {
            $this->addToAssertionCount(1);
        }
    }
}

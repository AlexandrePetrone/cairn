<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Release;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Release\CreateRelease;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * UpdateIssue. Test workflow `bug`: environment (select, required) and url
 * fields; everyone holds issue.edit. Test permissions: issue.assign for
 * managers and developers, issue.set_criticality for everyone,
 * issue.set_private for managers only, issue.set_release for managers
 * and developers, release.manage for managers.
 */
final class UpdateIssueHandlerTest extends KernelTestCase
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

    public function testTheTitleAndTheDescriptionChangeAndAreJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $client = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new UpdateIssue($client, $issue->getKey(), title: '  Export CSV des commandes vide  '));
        self::assertSame('Export CSV des commandes vide', $issue->getTitle());
        self::assertSame(['field' => 'title', 'from' => 'Export CSV vide', 'to' => 'Export CSV des commandes vide'], $this->lastEntry($issue, JournalEntryType::Changed)->getData());

        $this->commands->dispatch(new UpdateIssue($client, $issue->getKey(), description: 'Le fichier ne contient que l\'en-tête.'));
        self::assertSame('Le fichier ne contient que l\'en-tête.', $issue->getDescription());
        self::assertSame('description', $this->lastEntry($issue, JournalEntryType::Changed)->getData()['field']);
    }

    public function testTheContentIsEditedByTheAuthorOrWithTheRightOnOthers(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), description: 'Réécrit par le chef de projet.'));
        self::assertSame('Réécrit par le chef de projet.', $issue->getDescription());
        self::assertSame(['field' => 'description', 'from' => 'Le fichier téléchargé est vide.', 'to' => 'Réécrit par le chef de projet.'], $this->lastEntry($issue, JournalEntryType::Changed)->getData(), 'both versions are kept');
        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), title: 'Export CSV vide (prod)'));
        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), fields: ['environment' => 'preprod']));

        $developer = (int) $this->fixtures->developer->getId();
        foreach ([
            new UpdateIssue($developer, $issue->getKey(), description: 'Pas la mienne.'),
            new UpdateIssue($developer, $issue->getKey(), title: 'Pas la mienne'),
            new UpdateIssue($developer, $issue->getKey(), fields: ['environment' => 'staging']),
        ] as $command) {
            try {
                $this->commands->dispatch($command);
                self::fail($command::class.': the developer did not write the issue');
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame('Export CSV vide (prod)', $issue->getTitle());
    }

    public function testABlankTitleIsRefused(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->client->getId(), $issue->getKey(), title: '   '));
    }

    public function testFieldsAreMergedAndEachChangeIsJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client, fields: ['environment' => 'prod', 'url' => 'https://shop.test/a']);
        $client = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new UpdateIssue($client, $issue->getKey(), fields: ['environment' => 'preprod']));
        self::assertSame(['environment' => 'preprod', 'url' => 'https://shop.test/a'], $issue->getFieldValues());
        $entry = $this->lastEntry($issue, JournalEntryType::FieldChanged);
        self::assertSame(['field' => 'environment', 'label' => 'Environnement', 'from' => 'Production', 'to' => 'Préproduction'], $entry->getData());
        self::assertFalse($entry->isPrivate());

        $this->commands->dispatch(new UpdateIssue($client, $issue->getKey(), fields: ['url' => '', 'environment' => 'preprod']));
        self::assertSame(['environment' => 'preprod'], $issue->getFieldValues(), 'an emptied field is removed, an unchanged one untouched');
        self::assertSame(['field' => 'url', 'label' => 'URL', 'from' => 'https://shop.test/a', 'to' => null], $this->lastEntry($issue, JournalEntryType::FieldChanged)->getData());
    }

    public function testARequiredFieldCannotBeEmptied(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('is required');
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->client->getId(), $issue->getKey(), fields: ['environment' => null]));
    }

    public function testAFieldOutsideTheWorkflowIsRefused(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('is not used by workflow');
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->client->getId(), $issue->getKey(), fields: ['ghost' => 'x']));
    }

    public function testAnOutsiderMayNotEdit(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $outsider = $this->fixtures->access->user('paul@other.test', internal: true);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateIssue((int) $outsider->getId(), $issue->getKey(), title: 'Autre titre'));
    }

    public function testAnIssueIsAssignedToAMemberAndBackToNobody(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeId: $this->fixtures->developer->getId()));
        self::assertSame($this->fixtures->developer->getId(), $issue->getAssignee()?->getId());
        self::assertSame(['field' => 'assignee', 'from' => null, 'to' => 'Nadia Test'], $this->lastEntry($issue, JournalEntryType::Changed)->getData());

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeId: UpdateIssue::NOBODY));
        self::assertNull($issue->getAssignee());
        self::assertSame(['field' => 'assignee', 'from' => 'Nadia Test', 'to' => null], $this->lastEntry($issue, JournalEntryType::Changed)->getData());
    }

    public function testOnlyAMemberOfTheProjectMayBeAssigned(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $outsider = $this->fixtures->access->user('paul@other.test');

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('holds no role');
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), assigneeId: $outsider->getId()));
    }

    public function testADeactivatedMemberMayNotBeAssigned(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->fixtures->developer->deactivate();
        $this->entityManager->flush();

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('is deactivated');
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), assigneeId: $this->fixtures->developer->getId()));
    }

    public function testAClientMayNotAssign(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->client->getId(), $issue->getKey(), assigneeId: $this->fixtures->developer->getId()));
    }

    public function testTheCriticalityChangesAndAnUnchangedValueLeavesNoTrace(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $client = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new UpdateIssue($client, $issue->getKey(), criticality: Criticality::Blocking));
        self::assertSame(Criticality::Blocking, $issue->getCriticality());
        $entry = $this->lastEntry($issue, JournalEntryType::Changed);
        self::assertSame(['field' => 'criticality', 'from' => 'normal', 'to' => 'blocking'], $entry->getData());

        $this->commands->dispatch(new UpdateIssue($client, $issue->getKey(), criticality: Criticality::Blocking));
        self::assertSame($entry->getId(), $this->lastEntry($issue, JournalEntryType::Changed)->getId(), 'nothing journaled');
    }

    public function testAManagerHidesAnIssueAndShowsItAgain(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), private: true));
        self::assertTrue($issue->isPrivate());
        self::assertSame(['field' => 'visibility', 'from' => 'public', 'to' => 'private'], $this->lastEntry($issue, JournalEntryType::Changed)->getData());

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), private: false));
        self::assertFalse($issue->isPrivate());
    }

    public function testAnIssueOfAPrivateTrackerStaysPrivate(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('are private');
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), private: false));
    }

    public function testADeveloperMayNotChangeTheVisibility(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->developer->getId(), $issue->getKey(), private: true));
    }

    public function testSeveralPropertiesChangeTogetherEachJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new UpdateIssue(
            (int) $this->fixtures->manager->getId(),
            $issue->getKey(),
            title: 'Export CSV vide en production',
            assigneeId: $this->fixtures->developer->getId(),
            criticality: Criticality::High,
        ));

        $fields = array_map(
            static fn (JournalEntry $entry): mixed => $entry->getData()['field'] ?? null,
            $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $issue, 'type' => JournalEntryType::Changed], ['id' => 'ASC']),
        );
        self::assertSame(['title', 'assignee', 'criticality'], $fields);
    }

    public function testOneRefusedPropertyRefusesThemAll(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        try {
            $this->commands->dispatch(new UpdateIssue(
                (int) $this->fixtures->client->getId(),
                $issue->getKey(),
                title: 'Nouveau titre',
                assigneeId: $this->fixtures->developer->getId(),
            ));
            self::fail('A client may not assign.');
        } catch (AccessDeniedException) {
        }

        self::assertSame('Export CSV vide', $issue->getTitle(), 'checked before anything changes');
    }

    public function testAnIssueIsScheduledInAReleaseThenTakenOut(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $release = $this->release('2.5.0');
        $developer = (int) $this->fixtures->developer->getId();

        $this->commands->dispatch(new UpdateIssue($developer, $issue->getKey(), releaseId: $release->getId()));
        self::assertSame($release, $issue->getRelease());
        self::assertSame(['field' => 'release', 'from' => null, 'to' => '2.5.0'], $this->lastEntry($issue, JournalEntryType::Changed)->getData());

        $this->commands->dispatch(new UpdateIssue($developer, $issue->getKey(), releaseId: UpdateIssue::NOBODY));
        self::assertNull($issue->getRelease());
        self::assertSame(['field' => 'release', 'from' => '2.5.0', 'to' => null], $this->lastEntry($issue, JournalEntryType::Changed)->getData());
    }

    public function testAClientMayNotScheduleAnIssue(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $release = $this->release('2.5.0');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->client->getId(), $issue->getKey(), releaseId: $release->getId()));
    }

    public function testTheReleaseBelongsToTheProjectOfTheIssue(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $release = $this->commands->dispatch(new CreateRelease((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->intranet->getId(), '1.0.0'));
        \assert($release instanceof Release);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('does not belong');
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), releaseId: $release->getId()));
    }

    public function testADeliveredReleaseTakesNoNewIssueButKeepsItsOwn(): void
    {
        $manager = (int) $this->fixtures->manager->getId();
        $scheduled = $this->fixtures->createIssue($this->fixtures->client);
        $other = $this->fixtures->createIssue($this->fixtures->client, title: 'Typo');
        $release = $this->release('2.4.0');
        $this->commands->dispatch(new UpdateIssue($manager, $scheduled->getKey(), releaseId: $release->getId()));
        $release->deliver();
        $this->entityManager->flush();

        $this->commands->dispatch(new UpdateIssue($manager, $scheduled->getKey(), releaseId: $release->getId(), criticality: Criticality::High));
        self::assertSame($release, $scheduled->getRelease(), 'sending its release back unchanged refuses nothing');

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('no longer open');
        $this->commands->dispatch(new UpdateIssue($manager, $other->getKey(), releaseId: $release->getId()));
    }

    private function release(string $name): Release
    {
        $release = $this->commands->dispatch(new CreateRelease((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->shop->getId(), $name));
        \assert($release instanceof Release);

        return $release;
    }

    private function lastEntry(Issue $issue, JournalEntryType $type): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => $type], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}

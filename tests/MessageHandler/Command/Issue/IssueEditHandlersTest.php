<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeDescription;
use App\Message\Command\Issue\ChangeFields;
use App\Message\Command\Issue\ChangeTitle;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * ChangeTitle, ChangeDescription and ChangeFields. Test workflow `bug`:
 * environment (select, required) and url fields; everyone holds issue.edit.
 */
final class IssueEditHandlersTest extends KernelTestCase
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

        $this->commands->dispatch(new ChangeTitle($client, $issue->getKey(), '  Export CSV des commandes vide  '));
        self::assertSame('Export CSV des commandes vide', $issue->getTitle());
        self::assertSame(['field' => 'title', 'from' => 'Export CSV vide', 'to' => 'Export CSV des commandes vide'], $this->lastEntry($issue, JournalEntryType::Changed)->getData());

        $this->commands->dispatch(new ChangeDescription($client, $issue->getKey(), 'Le fichier ne contient que l\'en-tête.'));
        self::assertSame('Le fichier ne contient que l\'en-tête.', $issue->getDescription());
        self::assertSame('description', $this->lastEntry($issue, JournalEntryType::Changed)->getData()['field']);
    }

    public function testTheContentIsEditedByTheAuthorOrWithTheRightOnOthers(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new ChangeDescription($manager, $issue->getKey(), 'Réécrit par le chef de projet.'));
        self::assertSame('Réécrit par le chef de projet.', $issue->getDescription());
        self::assertSame(['field' => 'description', 'from' => 'Le fichier téléchargé est vide.', 'to' => 'Réécrit par le chef de projet.'], $this->lastEntry($issue, JournalEntryType::Changed)->getData(), 'both versions are kept');
        $this->commands->dispatch(new ChangeTitle($manager, $issue->getKey(), 'Export CSV vide (prod)'));
        $this->commands->dispatch(new ChangeFields($manager, $issue->getKey(), ['environment' => 'preprod']));

        $developer = (int) $this->fixtures->developer->getId();
        foreach ([
            new ChangeDescription($developer, $issue->getKey(), 'Pas la mienne.'),
            new ChangeTitle($developer, $issue->getKey(), 'Pas la mienne'),
            new ChangeFields($developer, $issue->getKey(), ['environment' => 'staging']),
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
        $this->commands->dispatch(new ChangeTitle((int) $this->fixtures->client->getId(), $issue->getKey(), '   '));
    }

    public function testFieldsAreMergedAndEachChangeIsJournaled(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client, fields: ['environment' => 'prod', 'url' => 'https://shop.test/a']);
        $client = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new ChangeFields($client, $issue->getKey(), ['environment' => 'preprod']));
        self::assertSame(['environment' => 'preprod', 'url' => 'https://shop.test/a'], $issue->getFieldValues());
        $entry = $this->lastEntry($issue, JournalEntryType::FieldChanged);
        self::assertSame(['field' => 'environment', 'label' => 'Environnement', 'from' => 'Production', 'to' => 'Préproduction'], $entry->getData());
        self::assertFalse($entry->isPrivate());

        $this->commands->dispatch(new ChangeFields($client, $issue->getKey(), ['url' => '', 'environment' => 'preprod']));
        self::assertSame(['environment' => 'preprod'], $issue->getFieldValues(), 'an emptied field is removed, an unchanged one untouched');
        self::assertSame(['field' => 'url', 'label' => 'URL', 'from' => 'https://shop.test/a', 'to' => null], $this->lastEntry($issue, JournalEntryType::FieldChanged)->getData());
    }

    public function testARequiredFieldCannotBeEmptied(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('is required');
        $this->commands->dispatch(new ChangeFields((int) $this->fixtures->client->getId(), $issue->getKey(), ['environment' => null]));
    }

    public function testAFieldOutsideTheWorkflowIsRefused(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(IssueException::class);
        $this->expectExceptionMessage('is not used by workflow');
        $this->commands->dispatch(new ChangeFields((int) $this->fixtures->client->getId(), $issue->getKey(), ['ghost' => 'x']));
    }

    public function testAnOutsiderMayNotEdit(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $outsider = $this->fixtures->access->user('paul@other.test', internal: true);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new ChangeTitle((int) $outsider->getId(), $issue->getKey(), 'Autre titre'));
    }

    private function lastEntry(Issue $issue, JournalEntryType $type): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => $type], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}

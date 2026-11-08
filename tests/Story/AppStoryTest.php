<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Entity\CodeReference;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Project;
use App\Entity\User;
use App\Story\AppStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The development data must keep loading as the model evolves.
 */
final class AppStoryTest extends KernelTestCase
{
    use Factories;

    public function testTheStoryBuildsAConsistentDataset(): void
    {
        self::bootKernel();
        AppStory::load();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $issues = $entityManager->getRepository(Issue::class)->findAll();

        self::assertGreaterThanOrEqual(40, \count($issues));
        self::assertSame(PlaceStatus::cases(), array_values(array_filter(PlaceStatus::cases(), static fn (PlaceStatus $status) => array_any($issues, static fn (Issue $issue) => $issue->getStatus() === $status))), 'every status is represented');

        foreach ($issues as $issue) {
            self::assertSame($issue->getProject()->getIssuePrefix().'-'.$issue->getNumber(), $issue->getKey());
            self::assertSame(PlaceStatus::Closed === $issue->getStatus(), null !== $issue->getClosedAt(), $issue->getKey().' closing date');
            if ($issue->getTracker()->isPrivate()) {
                self::assertTrue($issue->isPrivate(), $issue->getKey().' belongs to a private tracker');
            }
        }

        self::assertCount(\count($issues), $entityManager->getRepository(JournalEntry::class)->findBy(['type' => JournalEntryType::Created]));
        self::assertNotNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@cairn.test', 'admin' => true]));
        self::assertTrue($entityManager->getRepository(Project::class)->findOneBy(['key' => 'RES'])?->isArchived());
        $shopFour = $entityManager->getRepository(Issue::class)->findOneBy(['key' => 'MD-SPT-4']);
        self::assertCount(4, $entityManager->getRepository(CodeReference::class)->findBy(['issue' => $shopFour]), 'a merge request and three commits on MD-SPT-4');
    }
}

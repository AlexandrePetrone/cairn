<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Release;

use App\Entity\Enum\ReleaseStatus;
use App\Entity\JournalEntry;
use App\Entity\Release;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Release\CreateRelease;
use App\Message\Command\Release\DeleteRelease;
use App\Message\Command\Release\UpdateRelease;
use App\Message\CommandBus;
use App\MessageHandler\Command\Release\ReleaseException;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * CreateRelease, UpdateRelease and DeleteRelease. Test permissions: release.manage for
 * project managers only.
 */
final class ReleaseHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
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

    public function testAManagerCreatesAnOpenRelease(): void
    {
        $release = $this->create('  2.5.0 ', new \DateTimeImmutable('2026-11-20 15:30'), 'Checkout.');

        self::assertSame('2.5.0', $release->getName());
        self::assertSame('2026-11-20 00:00', $release->getPlannedOn()?->format('Y-m-d H:i'));
        self::assertSame('Checkout.', $release->getDescription());
        self::assertSame(ReleaseStatus::Open, $release->getStatus());
        self::assertSame($this->fixtures->access->shop, $release->getProject());
    }

    public function testANameIsUniqueWithinItsProject(): void
    {
        $this->create('2.5.0');
        $this->commands->dispatch(new CreateRelease((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->intranet->getId(), '2.5.0'));

        $this->expectException(ReleaseException::class);
        $this->create('2.5.0');
    }

    public function testARenamedReleaseKeepsItsNameUnique(): void
    {
        $this->create('2.5.0');
        $other = $this->create('2.6.0');
        $this->update($other, name: '2.6.0');

        $this->expectException(ReleaseException::class);
        $this->update($other, name: '2.5.0');
    }

    public function testOnlyWhoManagesReleasesCreatesOrChangesThem(): void
    {
        try {
            $this->commands->dispatch(new CreateRelease((int) $this->fixtures->developer->getId(), (int) $this->fixtures->access->shop->getId(), '2.5.0'));
            self::fail('A developer does not manage releases.');
        } catch (AccessDeniedException) {
        }

        $release = $this->create('2.5.0');
        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateRelease((int) $this->fixtures->client->getId(), (int) $release->getId(), status: ReleaseStatus::Delivered));
    }

    public function testNoReleaseIsCreatedInAnArchivedProject(): void
    {
        $this->fixtures->access->shop->archive();
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->create('2.5.0');
    }

    public function testAReleaseIsDeliveredThenReopenedOrAbandoned(): void
    {
        self::mockTime('2026-11-04 10:00');
        $release = $this->create('2.5.0', new \DateTimeImmutable('2026-11-20'));

        $this->update($release, status: ReleaseStatus::Delivered);
        self::assertSame(ReleaseStatus::Delivered, $release->getStatus());
        self::assertSame('2026-11-04 10:00', $release->getDeliveredAt()?->format('Y-m-d H:i'));

        $this->update($release, status: ReleaseStatus::Open);
        self::assertTrue($release->isOpen());
        self::assertNull($release->getDeliveredAt());

        $this->update($release, status: ReleaseStatus::Abandoned);
        self::assertSame(ReleaseStatus::Abandoned, $release->getStatus());
        self::assertNull($release->getDeliveredAt());
    }

    public function testADeletedReleaseLeavesItsIssuesWithoutOneAndTheirHistorySaysSo(): void
    {
        $release = $this->create('2.5.0');
        $manager = (int) $this->fixtures->manager->getId();
        $first = $this->fixtures->createIssue($this->fixtures->client);
        $internal = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks);
        $other = $this->fixtures->createIssue($this->fixtures->client);
        foreach ([$first, $internal] as $issue) {
            $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), releaseId: $release->getId()));
        }
        $this->update($release, status: ReleaseStatus::Delivered);
        $releaseId = (int) $release->getId();

        try {
            $this->commands->dispatch(new DeleteRelease((int) $this->fixtures->developer->getId(), $releaseId));
            self::fail('A developer does not manage releases.');
        } catch (AccessDeniedException) {
        }
        $this->commands->dispatch(new DeleteRelease($manager, $releaseId));

        self::assertNull($this->entityManager->getRepository(Release::class)->find($releaseId));
        self::assertNull($first->getRelease());
        self::assertNull($internal->getRelease());
        $entries = $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $first], ['id' => 'DESC'], 1);
        self::assertSame(['field' => 'release', 'from' => '2.5.0', 'to' => null], $entries[0]->getData());
        self::assertSame($this->fixtures->manager, $entries[0]->getAuthor());
        self::assertCount(1, $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $other]), 'an issue that was not in the release is left alone');
    }

    public function testAPropertyLeftNullKeepsItsValue(): void
    {
        $release = $this->create('2.5.0', new \DateTimeImmutable('2026-11-20'), 'Checkout.');

        $this->update($release, description: '');
        self::assertSame('2.5.0', $release->getName());
        self::assertNotNull($release->getPlannedOn());
        self::assertNull($release->getDescription());

        $this->update($release, unplanned: true);
        self::assertNull($release->getPlannedOn());
    }

    private function create(string $name, ?\DateTimeImmutable $plannedOn = null, ?string $description = null): Release
    {
        $release = $this->commands->dispatch(new CreateRelease((int) $this->fixtures->manager->getId(), (int) $this->fixtures->access->shop->getId(), $name, $plannedOn, $description));
        self::assertInstanceOf(Release::class, $release);

        return $release;
    }

    private function update(Release $release, ?string $name = null, bool $unplanned = false, ?string $description = null, ?ReleaseStatus $status = null): void
    {
        $this->commands->dispatch(new UpdateRelease((int) $this->fixtures->manager->getId(), (int) $release->getId(), name: $name, unplanned: $unplanned, description: $description, status: $status));
    }
}

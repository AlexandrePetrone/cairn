<?php

declare(strict_types=1);

namespace App\Tests\Entity\Type;

use App\Entity\AutomationRule;
use App\Entity\Issue;
use App\Message\Command\Automation\CreateRule;
use App\Repository\AutomationRunRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Dates are stored in UTC and read in the timezone of the instance, which
 * may change without moving any of them.
 */
final class UtcDateTimeImmutableTypeTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;

    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        self::bootKernel();
        self::applyConfiguration();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, self::commandBus());
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    public function testADateIsWrittenInUtc(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertSame('2026-03-03 10:00:00 EST', $issue->getCreatedAt()->format('Y-m-d H:i:s T'));
        self::assertSame('2026-03-03 15:00:00', $this->storedCreationOf($issue));
    }

    public function testADateIsReadInTheTimezoneOfTheInstanceEvenOnceItChanged(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $id = $this->fixtures->createIssue($this->fixtures->client)->getId();
        $written = self::mockTime()->now();

        date_default_timezone_set('Europe/Paris');
        $this->entityManager->clear();
        $issue = $this->entityManager->find(Issue::class, $id);

        self::assertNotNull($issue);
        self::assertSame('2026-03-03 16:00:00 CET', $issue->getCreatedAt()->format('Y-m-d H:i:s T'));
        self::assertEquals(new \DateTimeImmutable('2026-03-03 15:00:00 UTC'), $issue->getCreatedAt());
        self::assertGreaterThan($issue->getCreatedAt(), $written->modify('+1 second'));
    }

    public function testADateIsComparedWhateverItsTimezone(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $createdBy = fn (string $limit): int => (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Issue::class, 'i')
            ->where('i.id = :id AND i.createdAt <= :limit')
            ->setParameter('id', $issue->getId())
            ->setParameter('limit', new \DateTimeImmutable($limit))
            ->getQuery()
            ->getSingleScalarResult();

        self::assertSame(1, $createdBy('2026-03-03 16:00:00 Europe/Paris'));
        self::assertSame(0, $createdBy('2026-03-03 15:59:59 Europe/Paris'));
    }

    public function testADateReadFromAnAggregateIsTheOneWritten(): void
    {
        $rule = self::commandBus()->dispatch(new CreateRule((int) $this->fixtures->access->shop->getId(), 'Raise', null, [
            'trigger' => ['type' => 'issue_created'],
            'actions' => [['type' => 'set_criticality', 'params' => ['criticality' => 'high']]],
        ]));
        self::assertInstanceOf(AutomationRule::class, $rule);

        self::mockTime('2026-03-03 10:00:00');
        $this->fixtures->createIssue($this->fixtures->client);

        $last = self::getContainer()->get(AutomationRunRepository::class)->findLastRuns([$rule]);

        self::assertSame('2026-03-03 10:00:00 EST', $last[(int) $rule->getId()]->format('Y-m-d H:i:s T'));
    }

    private function storedCreationOf(Issue $issue): string
    {
        $table = $this->entityManager->getClassMetadata(Issue::class)->getTableName();

        return (string) $this->entityManager->getConnection()->fetchOne(\sprintf('SELECT created_at FROM %s WHERE id = ?', $table), [$issue->getId()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Worker;

use App\Entity\PendingNotification;
use App\Message\Query\Worker\ShowWorkerStatus;
use App\Message\Query\Worker\WorkerStatus;
use App\Notification\ProjectMailSender;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * What the administration reads of the queues of the worker. The messages
 * are written by the Doctrine transports themselves, which date them with
 * the real time: the clock is frozen now and only moves forward.
 */
final class ShowWorkerStatusTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;
    use WorkerQueues;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = self::mockTime();
        self::bootKernel();
    }

    public function testNothingWaitsOnAQuietInstance(): void
    {
        $status = self::queryBus()->ask(new ShowWorkerStatus());

        self::assertSame([0, null, 0, 0], [$status->waiting, $status->oldestWaiting, $status->failed, $status->lateNotifications]);
        self::assertFalse($status->messagesLate());
        self::assertFalse($status->notificationsLate());
    }

    public function testMessagesAreCountedWithTheAgeOfTheOldest(): void
    {
        self::asyncTransport()->send(new Envelope(new \stdClass()));
        self::asyncTransport()->send(new Envelope(new \stdClass(), [new DelayStamp(3_600_000)]));
        $this->clock->sleep(60);
        self::asyncTransport()->send(new Envelope(new \stdClass()));
        $this->clock->sleep(60);

        $status = self::queryBus()->ask(new ShowWorkerStatus());

        self::assertSame(2, $status->waiting, 'a message put off for an hour does not wait for a worker yet');
        self::assertEqualsWithDelta(120, $status->oldestWaiting, 5);
        self::assertFalse($status->messagesLate());
        self::assertSame(0, $status->failed);

        $this->clock->sleep(WorkerStatus::LATE);

        self::assertTrue(self::queryBus()->ask(new ShowWorkerStatus())->messagesLate());
    }

    public function testFailedMessagesAreCountedApart(): void
    {
        self::failedTransport()->send(new Envelope(new \stdClass()));
        $this->clock->sleep(3600);

        $status = self::queryBus()->ask(new ShowWorkerStatus());

        self::assertSame(1, $status->failed);
        self::assertSame(0, $status->waiting);
        self::assertFalse($status->messagesLate(), 'a failed message waits for an administrator, not for a worker');
    }

    public function testANotificationIsLateOnceItsMailShouldHaveLeft(): void
    {
        self::applyConfiguration();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $fixtures = new IssueFixtures($entityManager, self::commandBus());
        $issue = $fixtures->createIssue($fixtures->client);
        $entityManager->persist(new PendingNotification($fixtures->manager, $issue, $fixtures->client, [], null));
        $entityManager->flush();

        $this->clock->sleep(ProjectMailSender::MAX_WAIT);
        self::assertFalse(self::queryBus()->ask(new ShowWorkerStatus())->notificationsLate(), 'the sender may be about to take it');

        $this->clock->sleep(WorkerStatus::LATE);
        $status = self::queryBus()->ask(new ShowWorkerStatus());

        self::assertSame(1, $status->lateNotifications);
        self::assertTrue($status->notificationsLate());
    }
}

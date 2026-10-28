<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Issue;

use App\Message\Command\Issue\AddComment;
use App\Message\CommandBus;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AddCommentHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
    }

    public function testACommentIsAttachedAndCountsAsActivity(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::mockTime('2026-03-03 11:30:00');
        $comment = $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), '  Je regarde.  ', private: true));

        self::assertSame('Je regarde.', $comment->getBody());
        self::assertTrue($comment->isPrivate());
        self::assertSame($issue->getId(), $comment->getIssue()->getId());
        self::assertSame('2026-03-03 11:30:00', $issue->getUpdatedAt()->format('Y-m-d H:i:s'));
        self::assertNotNull($comment->getId());
    }

    public function testAClientCannotCommentPrivately(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('private');
        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'Psst', private: true));
    }
}

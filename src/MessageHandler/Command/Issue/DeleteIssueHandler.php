<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Event\IssueDeleted;
use App\Message\Attachment\RemoveFiles;
use App\Message\Command\Issue\DeleteIssue;
use App\Repository\IssueEraser;
use App\Security\AccessControl;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Erases the issue through IssueEraser, the one issue command that
 * leaves no journal: there is nothing left to hang it on. Its files go
 * once the transaction committed. An archived project grants nobody the
 * right, as for everything else.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly IssueEraser $eraser,
        private readonly EventDispatcherInterface $events,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(DeleteIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueDelete, $issue->getProject()), 'delete issues');

        $files = $this->eraser->eraseIssue($issue);
        $this->events->dispatch(new IssueDeleted($issue, $actor, []));
        if ([] !== $files) {
            $this->bus->dispatch(new RemoveFiles($files), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}

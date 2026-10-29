<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\AssignIssue;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class AssignIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AssignIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueAssign, $project), 'assign issues');

        $assignee = null === $command->assigneeId ? null : $this->support->actor($command->assigneeId);
        if (null !== $assignee && null === $this->access->roleOn($assignee, $project)) {
            throw IssueException::notAMember($assignee->getFullName(), $project->getName());
        }

        $previous = $issue->getAssignee();
        if ($previous?->getId() === $assignee?->getId()) {
            return;
        }

        $issue->assignTo($assignee);
        $this->entityManager->persist(JournalEntry::changed($issue, $actor, 'assignee', $previous?->getFullName(), $assignee?->getFullName()));
    }
}

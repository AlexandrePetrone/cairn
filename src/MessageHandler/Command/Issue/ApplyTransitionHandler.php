<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\ApplyTransition;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ApplyTransitionHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ApplyTransition $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();

        $workflowId = $issue->getTracker()->getWorkflowId();
        $definition = $this->workflows->definition($workflowId);
        $transition = $definition->transitions[$command->transitionId] ?? throw IssueException::unknownTransition($command->transitionId, $workflowId);

        $this->support->denyUnless($this->access->canTransition($actor, $transition, $project), \sprintf('apply transition "%s"', $transition->id));

        $workflow = $this->workflows->get($workflowId);
        if (!$workflow->can($issue, $transition->id)) {
            throw IssueException::transitionUnavailable($transition->id, $issue->getPlace());
        }

        $body = trim((string) $command->comment);
        if ('' === $body && $this->access->commentRequired($actor, $transition)) {
            throw IssueException::commentRequired($transition->id);
        }

        $comment = null;
        if ('' !== $body) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::CommentCreate, $project), 'comment');
            $this->support->denyUnless(!$command->commentPrivate || $this->access->isGranted($actor, Permission::CommentSetPrivate, $project), 'make comments private');
            $comment = new Comment($issue, $actor, $body, $command->commentPrivate);
            $this->entityManager->persist($comment);
            $this->support->claimAttachments($actor, $issue, $command->attachments, $body, $comment);
        }

        $from = $definition->place($issue->getPlace());
        $workflow->apply($issue, $transition->id, ['actor' => $actor]);
        $to = $definition->place($issue->getPlace());

        $this->entityManager->persist(JournalEntry::transitioned($issue, $actor, $transition->label, $from, $to, $comment));

        return $issue;
    }
}

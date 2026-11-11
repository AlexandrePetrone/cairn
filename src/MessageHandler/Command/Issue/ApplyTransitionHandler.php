<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Message\Command\Issue\ApplyTransition;
use App\Workflow\WorkflowProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ApplyTransitionHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function __invoke(ApplyTransition $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $workflowId = $issue->getTracker()->getWorkflowId();
        $transition = $this->workflows->definition($workflowId)->transitions[$command->transitionId] ?? throw IssueException::unknownTransition($command->transitionId, $workflowId);

        $this->support->transition($issue, $actor, $transition, $command->comment, $command->commentPrivate, $command->attachments);

        return $issue;
    }
}

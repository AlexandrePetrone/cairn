<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Event\IssueMoved;
use App\Message\Command\Issue\MoveIssue;
use App\Security\AccessControl;
use App\Security\PlaceMove;
use App\Workflow\WorkflowProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The one place that applies "the workflow first": the issue takes the
 * action of the actor that leads to the place (AccessControl::movesFor(),
 * the first when several do), as ApplyTransition would; only without one
 * is the place written directly, without the state machine, and the
 * journal says so (`moved`).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class MoveIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function __invoke(MoveIssue $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $workflow = $this->workflows->definition($issue->getTracker()->getWorkflowId());
        $from = $workflow->findPlace($issue->getPlace()) ?? throw IssueException::unknownPlace($issue->getPlace(), $workflow->id);
        if (!$workflow->hasPlace($command->placeId)) {
            throw IssueException::unknownPlace($command->placeId, $workflow->id);
        }
        $to = $workflow->place($command->placeId);
        if ($to->id === $from->id) {
            throw IssueException::samePlace($to->label);
        }

        $move = array_find($this->access->movesFor($actor, $issue), static fn (PlaceMove $move): bool => $move->place->id === $to->id)
            ?? throw new AccessDeniedException('Not allowed to move the issue without an action.');

        if (null !== $move->transition) {
            $this->support->transition($issue, $actor, $move->transition);

            return $issue;
        }

        $issue->moveTo($to);
        $this->support->arrive($issue);
        $this->support->record(new IssueMoved($issue, $actor, [JournalEntry::moved($issue, $actor, $from, $to)]));

        return $issue;
    }
}

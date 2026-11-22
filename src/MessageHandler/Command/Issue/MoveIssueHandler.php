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
 * The one place that applies a move (AccessControl::movesFor()): for who
 * moves freely the place is written directly, without the state machine,
 * and the journal says `moved`; a role held to the workflow takes its
 * action that leads to the place (the first when several do), as
 * ApplyTransition would, and goes nowhere else.
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
        $from = $this->workflows->placeOf($issue);
        if (!$workflow->hasPlace($command->placeId)) {
            throw IssueException::unknownPlace($command->placeId, $workflow->id);
        }
        $to = $this->workflows->place($command->placeId);
        if ($to->id === $from->id) {
            throw IssueException::samePlace($to->label);
        }

        $move = array_find($this->access->movesFor($actor, $issue), static fn (PlaceMove $move): bool => $move->place->id === $to->id)
            ?? throw new AccessDeniedException('Not allowed to move the issue there: no action of the actor leads to that place.');

        if (null !== $move->transition) {
            $this->support->transition($issue, $actor, $move->transition);

            return $issue;
        }

        $issue->moveTo($this->workflows->row($to));
        $this->support->arrive($issue);
        $this->support->record(new IssueMoved($issue, $actor, [JournalEntry::moved($issue, $actor, $from, $to)]));

        return $issue;
    }
}

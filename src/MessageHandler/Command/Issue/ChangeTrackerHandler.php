<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Event\IssueMoved;
use App\Message\Command\Issue\ChangeTracker;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Workflow\WorkflowProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The tracker and the place are written together, without a state
 * machine: the journal keeps both ends (`tracker_changed`).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ChangeTrackerHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly TrackerRepository $trackers,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function __invoke(ChangeTracker $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();
        $current = $issue->getTracker();

        $this->support->denyUnless($this->access->canMoveFreely($actor, $project), 'change the tracker');
        $tracker = $this->trackers->find($command->trackerId) ?? throw IssueException::notFound('Tracker', $command->trackerId);
        // A tracker the actor does not see does not exist for them, as in the lists: nothing of it is said.
        if ($tracker->isPrivate() && !$actor->isInternal()) {
            throw IssueException::notFound('Tracker', $command->trackerId);
        }

        if ($tracker->getId() === $current->getId()) {
            throw IssueException::sameTracker($tracker->getName());
        }
        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw IssueException::trackerOutsideProject($tracker->getName(), $project->getName());
        }
        if ($tracker->isArchived()) {
            throw IssueException::archivedTracker($tracker->getName());
        }

        $previous = $this->workflows->definition($current->getWorkflowId());
        $previousPlace = $this->workflows->placeOf($issue);

        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        if (!$workflow->hasPlace($command->placeId)) {
            throw IssueException::unknownPlace($command->placeId, $workflow->id);
        }
        $place = $this->workflows->place($command->placeId);

        $issue->changeTracker($tracker, $this->workflows->row($place));
        $this->support->arrive($issue);
        $this->support->record(new IssueMoved($issue, $actor, [JournalEntry::trackerChanged($issue, $actor, $current, $previousPlace, $tracker, $place)]));

        return $issue;
    }
}

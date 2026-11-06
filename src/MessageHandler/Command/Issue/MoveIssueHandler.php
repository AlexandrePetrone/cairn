<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\MoveIssue;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The place is written directly, without the state machine: no transition
 * took place, and the journal says so (`moved`, or `tracker_changed`).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class MoveIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly TrackerRepository $trackers,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MoveIssue $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();
        $current = $issue->getTracker();

        if (null === $command->trackerId || $command->trackerId === $current->getId()) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueForcePlace, $project), 'move the issue without an action');

            $workflow = $this->workflows->definition($current->getWorkflowId());
            $from = $workflow->findPlace($issue->getPlace()) ?? throw IssueException::unknownPlace($issue->getPlace(), $workflow->id);
            if (!$workflow->hasPlace($command->placeId)) {
                throw IssueException::unknownPlace($command->placeId, $workflow->id);
            }
            $to = $workflow->place($command->placeId);
            if ($to->id === $from->id) {
                throw IssueException::samePlace($to->label);
            }

            $issue->moveTo($to);
            $this->entityManager->persist(JournalEntry::moved($issue, $actor, $from, $to));

            return $issue;
        }

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueChangeTracker, $project), 'change the tracker');
        $tracker = $this->trackers->find($command->trackerId) ?? throw IssueException::notFound('Tracker', $command->trackerId);

        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw IssueException::trackerOutsideProject($tracker->getName(), $project->getName());
        }
        if (!$tracker->isActive()) {
            throw IssueException::inactiveTracker($tracker->getName());
        }
        if ($tracker->isPrivate() && !$actor->isInternal()) {
            throw IssueException::privateTracker($tracker->getName());
        }

        $previous = $this->workflows->definition($current->getWorkflowId());
        $previousPlace = $previous->findPlace($issue->getPlace()) ?? throw IssueException::unknownPlace($issue->getPlace(), $previous->id);

        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        if (!$workflow->hasPlace($command->placeId)) {
            throw IssueException::unknownPlace($command->placeId, $workflow->id);
        }
        $place = $workflow->place($command->placeId);

        $issue->changeTracker($tracker, $place);
        $this->entityManager->persist(JournalEntry::trackerChanged($issue, $actor, $current, $previousPlace, $tracker, $place));

        return $issue;
    }
}

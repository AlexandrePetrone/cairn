<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeTracker;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ChangeTrackerHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly TrackerRepository $trackers,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ChangeTracker $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();
        $tracker = $this->trackers->find($command->trackerId) ?? throw IssueException::notFound('Tracker', $command->trackerId);

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueChangeTracker, $project), 'change the tracker');

        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw IssueException::trackerOutsideProject($tracker->getName(), $project->getName());
        }
        if ($tracker->getId() === $issue->getTracker()->getId()) {
            throw IssueException::sameTracker($tracker->getName());
        }

        $previousTracker = $issue->getTracker();
        $previousPlace = $this->workflows->definition($previousTracker->getWorkflowId())->place((string) $issue->getPlace());

        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        if (!$workflow->hasPlace($command->placeId)) {
            throw IssueException::unknownPlace($command->placeId, $workflow->id);
        }
        $place = $workflow->place($command->placeId);

        $issue->changeTracker($tracker, $place);
        $this->entityManager->persist(JournalEntry::trackerChanged($issue, $actor, $previousTracker, $previousPlace, $tracker, $place));

        return $issue;
    }
}

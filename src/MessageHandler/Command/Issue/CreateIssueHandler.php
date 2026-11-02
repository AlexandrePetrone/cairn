<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\CreateIssue;
use App\Repository\ProjectRepository;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly ProjectRepository $projects,
        private readonly TrackerRepository $trackers,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateIssue $command): Issue
    {
        $actor = $this->support->actor($command->actorId);
        $project = $this->projects->find($command->projectId) ?? throw IssueException::notFound('Project', $command->projectId);
        $tracker = $this->trackers->find($command->trackerId) ?? throw IssueException::notFound('Tracker', $command->trackerId);

        // Refused by the permission check too; this one names the reason.
        if ($project->isReadOnly()) {
            throw IssueException::archivedProject($project->getName());
        }
        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw IssueException::trackerOutsideProject($tracker->getName(), $project->getName());
        }
        if (!$tracker->isActive()) {
            throw IssueException::inactiveTracker($tracker->getName());
        }

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueCreate, $project), 'create issues in this project');
        $this->support->denyUnless($actor->isInternal() || !$tracker->isPrivate(), 'use this tracker');
        $this->support->denyUnless(null === $command->assigneeId || $this->access->isGranted($actor, Permission::IssueAssign, $project), 'assign issues');
        $this->support->denyUnless(!$command->private || $this->access->isGranted($actor, Permission::IssueSetPrivate, $project), 'make issues private');
        $this->support->denyUnless(Criticality::DEFAULT === $command->criticality || $this->access->isGranted($actor, Permission::IssueSetCriticality, $project), 'set the criticality');

        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        $this->support->assertKnownFields($workflow, $command->fields);
        $this->support->assertRequiredFields($workflow, $command->fields);

        $assignee = $this->support->assignee($command->assigneeId, $project);

        // Lock the project row so that concurrent creations draw distinct numbers.
        $this->entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);

        $issue = new Issue($project, $tracker, $workflow->place($workflow->initial), $project->allocateIssueNumber(), $actor, $command->title, $command->description);
        $issue->setCriticality($command->criticality);
        $issue->assignTo($assignee);
        $issue->setFieldValues($command->fields);
        if ($command->private || $tracker->isPrivate()) {
            $issue->makePrivate();
        }

        $this->entityManager->persist($issue);
        $this->entityManager->persist(JournalEntry::created($issue, $actor));

        // Files sent while writing the issue were uploaded before it existed.
        $this->support->claimAttachments($actor, $issue, $command->attachments, $command->description);

        return $issue;
    }
}

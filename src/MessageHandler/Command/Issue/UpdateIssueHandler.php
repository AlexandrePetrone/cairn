<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Message\Command\Issue\UpdateIssue;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every right is checked before anything changes, so that a refused
 * property leaves the others untouched. The content (title, description,
 * fields) follows AccessControl::canEditIssue(), the other properties
 * their own permission. A property that already holds the value is left
 * alone and not journaled; the others get one journal entry each, with
 * the values of the time (private for an internal-only field).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UpdateIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();

        if (null !== $command->title || null !== $command->description || null !== $command->fields) {
            $this->support->denyUnless($this->access->canEditIssue($actor, $issue), 'edit an issue written by someone else');
        }
        $fieldChanges = null !== $command->fields ? $this->fieldChanges($actor, $issue, $command->fields) : [];
        $assignee = null;
        if (null !== $command->assigneeId) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueAssign, $project), 'assign issues');
            $assignee = $this->support->assignee(UpdateIssue::NOBODY === $command->assigneeId ? null : $command->assigneeId, $project);
        }
        if (null !== $command->criticality) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetCriticality, $project), 'set the criticality');
        }
        if (null !== $command->private) {
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetPrivate, $project), 'change the visibility');
            if (!$command->private && $issue->getTracker()->isPrivate()) {
                throw IssueException::privateTracker($issue->getTracker()->getName());
            }
        }

        if (null !== $command->title && $issue->getTitle() !== trim($command->title)) {
            $previous = $issue->getTitle();
            $issue->retitle($command->title);
            $this->journal(JournalEntry::changed($issue, $actor, 'title', $previous, $issue->getTitle()));
        }
        if (null !== $command->description && $issue->getDescription() !== $command->description) {
            $previous = $issue->getDescription();
            $issue->describe($command->description);
            $this->journal(JournalEntry::changed($issue, $actor, 'description', $previous, $issue->getDescription()));
            // Images pasted while rewriting it were uploaded pending.
            $this->support->claimAttachments($actor, $issue, [], $command->description);
        }
        if ([] !== $fieldChanges) {
            $previous = $issue->getFieldValues();
            $issue->changeFieldValues($fieldChanges);
            foreach ($fieldChanges as $fieldId => $value) {
                $this->journal(JournalEntry::fieldChanged($issue, $actor, $this->config->definition()->fields[$fieldId], $previous[$fieldId] ?? null, $value));
            }
        }
        if (null !== $command->assigneeId && $issue->getAssignee()?->getId() !== $assignee?->getId()) {
            $previous = $issue->getAssignee();
            $issue->assignTo($assignee);
            $this->journal(JournalEntry::changed($issue, $actor, 'assignee', $previous?->getFullName(), $assignee?->getFullName()));
        }
        if (null !== $command->criticality && $issue->getCriticality() !== $command->criticality) {
            $previous = $issue->getCriticality();
            $issue->setCriticality($command->criticality);
            $this->journal(JournalEntry::changed($issue, $actor, 'criticality', $previous->value, $command->criticality->value));
        }
        if (null !== $command->private && $issue->isPrivate() !== $command->private) {
            $command->private ? $issue->makePrivate() : $issue->makePublic();
            $this->journal(JournalEntry::changed($issue, $actor, 'visibility', $command->private ? 'public' : 'private', $command->private ? 'private' : 'public'));
        }
    }

    /**
     * The values that actually change, merged into what the issue holds:
     * the fields not listed keep their value, the values of fields the
     * workflow left are kept and ignored. Required fields must stay
     * filled, internal-only fields are for internal users.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> field id => new value, null when emptied
     */
    private function fieldChanges(User $actor, Issue $issue, array $values): array
    {
        $definition = $this->config->definition();
        $workflow = $definition->workflow($issue->getTracker()->getWorkflowId());
        $this->support->assertKnownFields($workflow, $values);

        $current = $issue->getFieldValues();
        $changes = [];
        foreach ($values as $fieldId => $value) {
            $value = '' === $value ? null : $value;
            if (($current[$fieldId] ?? null) !== $value) {
                $changes[$fieldId] = $value;
            }
        }

        $this->support->assertRequiredFields($workflow, array_filter(array_replace($current, $changes), static fn (mixed $value): bool => null !== $value));
        foreach (array_keys($changes) as $fieldId) {
            $this->support->denyUnless($actor->isInternal() || !$definition->fields[$fieldId]->internalOnly, 'edit internal fields');
        }

        return $changes;
    }

    private function journal(JournalEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\ConfigRegistry;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeFields;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Merges the new values into the issue, one journal entry per field that
 * actually changed, private for internal-only fields.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ChangeFieldsHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ChangeFields $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->support->denyUnless($this->access->canEditIssue($actor, $issue), 'change the fields of an issue written by someone else');

        $definition = $this->config->definition();
        $workflow = $definition->workflow($issue->getTracker()->getWorkflowId());
        $this->support->assertKnownFields($workflow, $command->values);

        $current = $issue->getFieldValues();
        $changes = [];
        foreach ($command->values as $fieldId => $value) {
            $value = '' === $value ? null : $value;
            if (($current[$fieldId] ?? null) !== $value) {
                $changes[$fieldId] = $value;
            }
        }

        // What the issue will hold: the values of fields the workflow left are kept, and ignored here.
        $this->support->assertRequiredFields($workflow, array_filter(array_replace($current, $changes), static fn (mixed $value): bool => null !== $value));
        foreach (array_keys($changes) as $fieldId) {
            $this->support->denyUnless($actor->isInternal() || !$definition->fields[$fieldId]->internalOnly, 'edit internal fields');
        }
        if ([] === $changes) {
            return;
        }

        $issue->changeFieldValues($changes);
        foreach ($changes as $fieldId => $value) {
            $this->entityManager->persist(JournalEntry::fieldChanged($issue, $actor, $definition->fields[$fieldId], $current[$fieldId] ?? null, $value));
        }
    }
}

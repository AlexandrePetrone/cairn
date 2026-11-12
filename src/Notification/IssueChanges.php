<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;

/**
 * An issue and what happened to it since the last mail, oldest first: one
 * card of the project mail.
 */
final readonly class IssueChanges
{
    /**
     * @param non-empty-list<IssueChange> $changes
     */
    public function __construct(
        public Issue $issue,
        public array $changes,
    ) {
    }

    /** The issue was created in this mail: its description comes along. */
    public function created(): bool
    {
        foreach ($this->changes as $change) {
            foreach ($change->entries as $entry) {
                if (JournalEntryType::Created === $entry->getType()) {
                    return true;
                }
            }
        }

        return false;
    }
}

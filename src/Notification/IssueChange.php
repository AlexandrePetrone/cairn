<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Comment;
use App\Entity\JournalEntry;
use App\Entity\User;

/**
 * What one command did to an issue, as one recipient may read it: the
 * journal lines it wrote and the comment that came with it.
 */
final readonly class IssueChange
{
    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        public ?User $actor,
        public \DateTimeImmutable $at,
        public array $entries,
        public ?Comment $comment,
        public ?string $rule = null,
    ) {
    }

    /** Who the change reads as made by: the person, or the automation rule. */
    public function by(): string
    {
        return $this->actor?->getFullName() ?? (string) $this->rule;
    }

    /** What tells whether two changes in a row were made by the same person, or the same rule: the mail names them once. */
    public function authorKey(): string
    {
        return null !== $this->actor ? 'user-'.$this->actor->getId() : 'rule-'.$this->rule;
    }
}

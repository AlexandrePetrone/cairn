<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry as Entry;
use App\Twig\JournalLabelExtension;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One line of the history of an issue: who did what, when. The sentence
 * comes from the `journal_label` filter (Twig/JournalLabelExtension), the
 * texts a rewriting or a deletion kept are readable behind a disclosure.
 */
#[AsTwigComponent('Issue:JournalEntry')]
final class JournalEntry
{
    public Entry $entry;

    public function __construct(
        private readonly JournalLabelExtension $labels,
    ) {
    }

    public function getLabel(): string
    {
        return $this->labels->label($this->entry);
    }

    /**
     * The text before and after, when the entry recorded a rewriting (a
     * description, a comment).
     *
     * @return array{from: string, to: string}|null
     */
    public function getVersions(): ?array
    {
        $data = $this->entry->getData();
        $rewrote = match ($this->entry->getType()) {
            JournalEntryType::CommentEdited => true,
            JournalEntryType::Changed => 'description' === ($data['field'] ?? null) && null !== ($data['from'] ?? null),
            default => false,
        };

        return $rewrote ? ['from' => $this->text($data, 'from'), 'to' => $this->text($data, 'to')] : null;
    }

    /** The text of a deleted comment, which lives on in its entry. */
    public function getDeletedBody(): ?string
    {
        return JournalEntryType::CommentDeleted === $this->entry->getType() ? $this->text($this->entry->getData(), 'body') : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key, string $default = ''): string
    {
        return $this->entry->text($key, $default);
    }
}

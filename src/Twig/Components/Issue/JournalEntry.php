<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry as Entry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One line of the history of an issue: who did what, when. The entry
 * keeps ids and labels of the time; the sentence is built here from what
 * the type recorded, with the values translated when they are ours
 * (criticality, visibility, "nobody", no release).
 */
#[AsTwigComponent('Issue:JournalEntry')]
final class JournalEntry
{
    public Entry $entry;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getLabel(): string
    {
        $data = $this->entry->getData();
        $type = $this->entry->getType();

        return match ($type) {
            JournalEntryType::Created => $this->translator->trans('issue.event.created'),
            JournalEntryType::Transitioned => $this->translator->trans('issue.event.transitioned', ['%transition%' => $this->text($data, 'transition'), '%from%' => $this->text($data, 'from'), '%to%' => $this->text($data, 'to')]),
            JournalEntryType::TrackerChanged => $this->translator->trans('issue.event.tracker_changed', ['%from_tracker%' => $this->text($data, 'from_tracker'), '%to_tracker%' => $this->text($data, 'to_tracker'), '%to%' => $this->text($data, 'to')]),
            JournalEntryType::Moved => $this->translator->trans('issue.event.moved', ['%from%' => $this->text($data, 'from'), '%to%' => $this->text($data, 'to')]),
            JournalEntryType::FieldChanged => $this->translator->trans('issue.event.field_changed', ['%field%' => $this->text($data, 'label'), '%from%' => $this->text($data, 'from', '—'), '%to%' => $this->text($data, 'to', '—')]),
            JournalEntryType::Changed => $this->changedLabel($data),
            JournalEntryType::CommentEdited, JournalEntryType::CommentDeleted => $this->translator->trans(
                \sprintf('issue.event.%s.%s', $type->value, ($data['author_id'] ?? null) === $this->entry->getAuthor()?->getId() ? 'own' : 'other'),
                ['%author%' => $this->text($data, 'author')],
            ),
            JournalEntryType::Linked, JournalEntryType::Unlinked => $this->translator->trans('issue.event.'.$type->value, ['%label%' => $this->text($data, 'label'), '%key%' => $this->text($data, 'key'), '%title%' => $this->text($data, 'title')]),
            JournalEntryType::AttachmentAdded, JournalEntryType::AttachmentRemoved => $this->translator->trans('issue.event.'.$type->value, ['%filename%' => $this->text($data, 'filename')]),
        };
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
    private function changedLabel(array $data): string
    {
        $field = $this->text($data, 'field');
        if ('description' === $field) {
            return $this->translator->trans('issue.event.changed.description');
        }

        $shown = fn (string $key): string => match (true) {
            !isset($data[$key]) => $this->translator->trans('release' === $field ? 'issue.show.no_release' : 'issue.show.nobody'),
            'criticality' === $field => $this->translator->trans('criticality.'.$this->text($data, $key)),
            'visibility' === $field => $this->translator->trans('issue.show.'.('private' === $data[$key] ? 'hidden' : 'visible').'_externals'),
            default => $this->text($data, $key),
        };

        return $this->translator->trans('issue.event.changed.'.$field, ['%from%' => $shown('from'), '%to%' => $shown('to')]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key, string $default = ''): string
    {
        return $this->entry->text($key, $default);
    }
}

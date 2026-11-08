<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Enum\JournalEntryType;
use App\Entity\JournalEntry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * The sentence of a journal entry, without its subject: "created the
 * issue", "applied « Validate »: To test → Done". The entry keeps ids and
 * labels of the time; this is the only place that reads what each type
 * recorded, for the thread of the issue page (Issue:JournalEntry) and for
 * the mails alike. Values that are ours are translated (criticality,
 * visibility, "nobody", no release).
 */
final class JournalLabelExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsTwigFilter('journal_label')]
    public function label(JournalEntry $entry): string
    {
        $data = $entry->getData();
        $type = $entry->getType();

        return match ($type) {
            JournalEntryType::Created => $this->translator->trans('issue.event.created'),
            JournalEntryType::Transitioned => $this->translator->trans('issue.event.transitioned', ['%transition%' => $entry->text('transition'), '%from%' => $entry->text('from'), '%to%' => $entry->text('to')]),
            JournalEntryType::TrackerChanged => $this->translator->trans('issue.event.tracker_changed', ['%from_tracker%' => $entry->text('from_tracker'), '%to_tracker%' => $entry->text('to_tracker'), '%to%' => $entry->text('to')]),
            JournalEntryType::Moved => $this->translator->trans('issue.event.moved', ['%from%' => $entry->text('from'), '%to%' => $entry->text('to')]),
            JournalEntryType::FieldChanged => $this->translator->trans('issue.event.field_changed', ['%field%' => $entry->text('label'), '%from%' => $entry->text('from', '—'), '%to%' => $entry->text('to', '—')]),
            JournalEntryType::Changed => $this->changedLabel($entry),
            JournalEntryType::CommentEdited, JournalEntryType::CommentDeleted => $this->translator->trans(
                \sprintf('issue.event.%s.%s', $type->value, ($data['author_id'] ?? null) === $entry->getAuthor()?->getId() ? 'own' : 'other'),
                ['%author%' => $entry->text('author')],
            ),
            JournalEntryType::Linked, JournalEntryType::Unlinked => $this->translator->trans('issue.event.'.$type->value, ['%label%' => $entry->text('label'), '%key%' => $entry->text('key'), '%title%' => $entry->text('title')]),
            JournalEntryType::AttachmentAdded, JournalEntryType::AttachmentRemoved => $this->translator->trans('issue.event.'.$type->value, ['%filename%' => $entry->text('filename')]),
            JournalEntryType::CommitsPushed => $this->translator->trans('issue.event.commits_pushed', ['%count%' => \count((array) ($data['commits'] ?? [])), '%branch%' => $entry->text('branch'), '%repository%' => $entry->text('repository')]),
            JournalEntryType::MergeRequestOpened, JournalEntryType::MergeRequestMerged, JournalEntryType::MergeRequestClosed, JournalEntryType::MergeRequestReopened => $this->translator->trans('issue.event.'.$type->value, ['%number%' => $entry->text('number'), '%title%' => $entry->text('title'), '%repository%' => $entry->text('repository')]),
        };
    }

    private function changedLabel(JournalEntry $entry): string
    {
        $data = $entry->getData();
        $field = $entry->text('field');
        if ('description' === $field) {
            return $this->translator->trans('issue.event.changed.description');
        }

        $shown = fn (string $key): string => match (true) {
            !isset($data[$key]) => $this->translator->trans('release' === $field ? 'issue.show.no_release' : 'issue.show.nobody'),
            'criticality' === $field => $this->translator->trans('criticality.'.$entry->text($key)),
            'visibility' === $field => $this->translator->trans('issue.show.'.('private' === $data[$key] ? 'hidden' : 'visible').'_externals'),
            default => $entry->text($key),
        };

        return $this->translator->trans('issue.event.changed.'.$field, ['%from%' => $shown('from'), '%to%' => $shown('to')]);
    }
}

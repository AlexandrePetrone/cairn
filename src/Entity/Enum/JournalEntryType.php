<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a journal entry records. Cases are added with the command that
 * produces them.
 */
enum JournalEntryType: string
{
    case Created = 'created';
    case Transitioned = 'transitioned';
    case TrackerChanged = 'tracker_changed';
    /** The place was set without a transition. */
    case Moved = 'moved';
    case Changed = 'changed';
    case FieldChanged = 'field_changed';
    case AttachmentAdded = 'attachment_added';
    case AttachmentRemoved = 'attachment_removed';
    case CommentEdited = 'comment_edited';
    case CommentDeleted = 'comment_deleted';
    case Linked = 'linked';
    case Unlinked = 'unlinked';
    /** A forge delivered commits mentioning the issue: one entry per push, with the new commits. */
    case CommitsPushed = 'commits_pushed';
    /** A merge request mentioning the issue was seen, or changed state. */
    case MergeRequestOpened = 'merge_request_opened';
    case MergeRequestMerged = 'merge_request_merged';
    case MergeRequestClosed = 'merge_request_closed';
    case MergeRequestReopened = 'merge_request_reopened';
    /** The issue was brought from another tracker: where it came from, and what was not kept. */
    case Imported = 'imported';

    /** Written by a forge, without an author: shown as a card in the thread. */
    public function isCode(): bool
    {
        return match ($this) {
            self::CommitsPushed, self::MergeRequestOpened, self::MergeRequestMerged, self::MergeRequestClosed, self::MergeRequestReopened => true,
            default => false,
        };
    }
}

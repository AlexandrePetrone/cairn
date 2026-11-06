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
}

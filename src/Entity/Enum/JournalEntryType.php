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
}

<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a line of an import's record is about: the account of a person,
 * the access given to it, a tracker, a release or an issue.
 */
enum ImportRecordKind: string
{
    case User = 'user';
    case Membership = 'membership';
    case Tracker = 'tracker';
    case Release = 'release';
    case Issue = 'issue';
}

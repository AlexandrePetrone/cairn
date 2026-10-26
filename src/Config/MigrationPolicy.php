<?php

declare(strict_types=1);

namespace App\Config;

/**
 * What the sync does with issues sitting on a place that disappears without
 * a `migrateFrom` rule on one of the new places.
 */
enum MigrationPolicy: string
{
    /** Refuse to apply; the previous snapshot stays active. */
    case Strict = 'strict';
    /** Move the issues to the workflow's fallback place. */
    case Fallback = 'fallback';
}

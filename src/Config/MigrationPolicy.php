<?php

declare(strict_types=1);

namespace App\Config;

/**
 * What the sync does with issues sitting on a place that disappears without
 * a rule for it in the `migrations` of its workflow.
 */
enum MigrationPolicy: string
{
    /** Refuse to apply; the previous snapshot stays active. */
    case Strict = 'strict';
    /** Move the issues to the workflow's fallback place. */
    case Fallback = 'fallback';
}

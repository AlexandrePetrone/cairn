<?php

declare(strict_types=1);

namespace App\Config\Sync;

enum MigrationReason: string
{
    /** The target place declares the removed place in `migrateFrom`. */
    case MigrateFrom = 'migrate_from';
    /** No rule matched; the workflow's fallback place is used. */
    case Fallback = 'fallback';
}

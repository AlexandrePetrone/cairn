<?php

declare(strict_types=1);

namespace App\Config\Snapshot;

use App\Config\ConfigException;

/**
 * No configuration has been applied yet: the application cannot run until
 * `app:config:sync` has created the first snapshot.
 */
final class NoSnapshotException extends ConfigException
{
}

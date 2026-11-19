<?php

declare(strict_types=1);

namespace App\Message\Query\Worker;

use App\Message\Query\Query;

/**
 * What waits for the worker, for the administration: nobody else reads it.
 *
 * @implements Query<WorkerStatus>
 */
final readonly class ShowWorkerStatus implements Query
{
}

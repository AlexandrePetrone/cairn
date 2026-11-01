<?php

declare(strict_types=1);

namespace App\Message\Query\Config;

use App\Message\Query\Query;

/**
 * Where a workflow of the configuration is used: the trackers bound to it
 * and how many issues sit on each of its places.
 *
 * @implements Query<WorkflowUsage>
 */
final readonly class ShowWorkflowUsage implements Query
{
    public function __construct(
        public string $workflowId,
    ) {
    }
}

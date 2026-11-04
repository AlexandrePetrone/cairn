<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Enum\PlaceStatus;

/**
 * A place of a workflow as a list offers it, to filter or to group by.
 * `key` (`workflow:place`, PlaceCatalog::key()) names it in the address and
 * in the groups; the workflow's label tells the places of two workflows
 * apart on a list that mixes them. The status only colours it.
 */
final readonly class PlaceChoice
{
    public function __construct(
        public string $key,
        public string $label,
        public string $workflowLabel,
        public PlaceStatus $status,
    ) {
    }
}

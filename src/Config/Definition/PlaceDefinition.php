<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Entity\Enum\PlaceStatus;

/**
 * A step issues may stand on, declared once for the instance: workflows
 * name the ones they use. The id is what issues store; the label may change
 * freely. Their order is the order of the filters and of the groups
 * of the issue lists; the columns of a kanban follow the workflow.
 */
final readonly class PlaceDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public PlaceStatus $status,
    ) {
    }
}

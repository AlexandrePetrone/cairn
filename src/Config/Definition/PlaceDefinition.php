<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Entity\Enum\PlaceStatus;

/**
 * A step of a workflow. The id is what issues store; the label may change
 * freely. Declaration order is the kanban column order.
 */
final readonly class PlaceDefinition
{
    /** @var list<string> */
    public array $migrateFrom;

    /**
     * @param string|null  $hint        explanation shown on the issue page when it sits on this place
     * @param list<string> $migrateFrom ids of removed places whose issues move here on sync
     */
    public function __construct(
        public string $id,
        public string $label,
        public PlaceStatus $status,
        public ?string $hint = null,
        array $migrateFrom = [],
    ) {
        Definitions::assertId($id, 'places');
        Definitions::assertLabel($label, "places.$id.label");
        $this->migrateFrom = Definitions::assertIdList($migrateFrom, "places.$id.migrateFrom");
    }
}

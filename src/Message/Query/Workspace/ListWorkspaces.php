<?php

declare(strict_types=1);

namespace App\Message\Query\Workspace;

use App\Entity\Workspace;
use App\Message\Query\Query;

/**
 * Every workspace, for the administration, archived ones last. `q` finds
 * a workspace by its name, its key or the name of one of its projects;
 * `archived` keeps the archived or the active ones, both when null.
 *
 * @implements Query<list<Workspace>>
 */
final readonly class ListWorkspaces implements Query
{
    public function __construct(
        public string $q = '',
        public ?bool $archived = null,
    ) {
    }
}

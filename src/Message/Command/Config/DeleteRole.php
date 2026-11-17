<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Deletes a role nobody holds and no action of a workflow names. Handled
 * by DeleteRoleHandler.
 */
final readonly class DeleteRole
{
    public function __construct(
        public string $id,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Takes a permission away from a role; not held, nothing changes. Handled
 * by RevokePermissionHandler.
 */
final readonly class RevokePermission implements ConfigurationCommand
{
    public function __construct(
        public string $roleId,
        public string $permission,
    ) {
    }
}

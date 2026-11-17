<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Gives a role a permission outside the workflows; already held, nothing
 * changes. Handled by GrantPermissionHandler.
 */
final readonly class GrantPermission implements ConfigurationCommand
{
    public function __construct(
        public string $roleId,
        public string $permission,
    ) {
    }
}

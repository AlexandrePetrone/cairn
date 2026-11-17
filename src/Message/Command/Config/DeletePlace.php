<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Deletes a step no workflow uses. Handled by DeletePlaceHandler.
 */
final readonly class DeletePlace implements ConfigurationCommand
{
    public function __construct(
        public string $id,
    ) {
    }
}

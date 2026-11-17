<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Deletes a field no workflow asks for. The values issues hold of it stay
 * stored, shown nowhere. Handled by DeleteFieldHandler.
 */
final readonly class DeleteField
{
    public function __construct(
        public string $id,
    ) {
    }
}

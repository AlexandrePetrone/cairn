<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * Deletes a type of link no link is of. Handled by DeleteLinkTypeHandler.
 */
final readonly class DeleteLinkType
{
    public function __construct(
        public string $id,
    ) {
    }
}

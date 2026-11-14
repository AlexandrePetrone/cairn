<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

/**
 * Gives up an import waiting for its configuration, failed, or left by
 * its worker: it never resumes, what it wrote stays, and its archive and
 * its project are free for another import.
 */
final readonly class AbandonImport
{
    public function __construct(
        public int $importId,
    ) {
    }
}

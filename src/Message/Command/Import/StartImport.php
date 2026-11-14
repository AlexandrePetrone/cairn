<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

/**
 * Starts the work of an import: its analysis, from the beginning each
 * time (the archive may have been fixed and dropped again), or once
 * configured its run, resumed where it stopped. An import in progress
 * starts again only when no batch moved it on for a while. The worker
 * takes it from there, batch after batch (AdvanceImport). Handled by
 * StartImportHandler.
 */
final readonly class StartImport
{
    public function __construct(
        public int $importId,
    ) {
    }
}

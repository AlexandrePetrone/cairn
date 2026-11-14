<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Import\ImportsDirectory;
use App\Message\Command\Import\AbandonImport;
use App\Repository\ImportRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class AbandonImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly ImportsDirectory $directory,
    ) {
    }

    public function __invoke(AbandonImport $command): void
    {
        $import = $this->imports->find($command->importId) ?? throw ImportException::notFound('Import', $command->importId);
        if (!$import->isAbandonable()) {
            throw ImportException::wrongState();
        }
        $import->abandon();
        $this->directory->release($import);
    }
}

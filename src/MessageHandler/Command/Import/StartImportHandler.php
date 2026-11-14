<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Entity\Enum\ImportStatus;
use App\Import\ImportAnalyser;
use App\Import\ImportMapping;
use App\Import\ImportMappingChecker;
use App\Import\ImportRunner;
use App\Import\ImportsDirectory;
use App\Message\Command\Import\StartImport;
use App\Message\Import\AdvanceImport;
use App\Repository\ImportRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[AsMessageHandler(bus: 'command.bus')]
final class StartImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly ImportMappingChecker $checker,
        private readonly ImportsDirectory $directory,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(StartImport $command): void
    {
        $import = $this->imports->find($command->importId) ?? throw ImportException::notFound('Import', $command->importId);
        $status = $import->getStatus();
        if ($status->isFinished() || ($status->isInProgress() && null !== $import->getRunToken() && !$import->isStale())) {
            throw ImportException::wrongState();
        }
        if (ImportStatus::Configuring === $status) {
            $this->checker->check($import, ImportMapping::fromArray($import->getMapping()), complete: true);
        }

        if (!$import->isAnalysed()) {
            // What an earlier analysis extracted is dropped: the zip is read again as it is now.
            $this->directory->release($import);
        }

        $token = bin2hex(random_bytes(16));
        $import->start($token, $import->isAnalysed() ? ImportRunner::FIRST_PHASE : ImportAnalyser::FIRST_PHASE);
        // After the commit: the worker reads the import as started.
        $this->bus->dispatch(new AdvanceImport((int) $import->getId(), $token), [new DispatchAfterCurrentBusStamp()]);
    }
}

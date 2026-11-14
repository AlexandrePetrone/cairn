<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Config\ConfigRegistry;
use App\Entity\Enum\ImportStatus;
use App\Import\ImportAnalysis;
use App\Import\ImportManifest;
use App\Import\ImportMapping;
use App\Import\ImportMappingChecker;
use App\Message\Command\Import\ConfigureImport;
use App\Repository\ImportRepository;
use App\Repository\TrackerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ConfigureImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly TrackerRepository $trackers,
        private readonly ImportMappingChecker $checker,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function __invoke(ConfigureImport $command): void
    {
        $import = $this->imports->find($command->importId) ?? throw ImportException::notFound('Import', $command->importId);
        if (ImportStatus::Configuring !== $import->getStatus()) {
            throw ImportException::wrongState();
        }

        $manifest = ImportManifest::fromArray($import->getManifest());
        $definition = $this->config->definition();
        $changes = array_filter(get_object_vars($command), static fn (mixed $value): bool => null !== $value);
        unset($changes['importId'], $changes['people']);
        $mapping = ImportMapping::fromArray($import->getMapping())->with($changes);
        if (null !== $command->people) {
            $mapping = $mapping->with(['people' => array_replace($mapping->people, $command->people)]);
        }
        $this->checker->check($import, $mapping);

        $import->configure($mapping->withGuessedPlaces(
            $manifest,
            ImportAnalysis::fromArray($import->getAnalysis()),
            $definition,
            $this->trackers->findByProject($import->getProject(), includeInactive: true),
        )->toArray());
    }
}

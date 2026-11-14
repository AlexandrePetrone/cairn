<?php

declare(strict_types=1);

namespace App\Import;

use App\Config\ConfigRegistry;
use App\Entity\Import;
use App\Repository\TrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reads an archive before anything is written, one batch at a time: its
 * manifest first (`read`), then every issue (`scan`), each one checked
 * against the manifest and its files looked up in the zip, and counted.
 * One thing wrong refuses the whole archive. When all is read, it guesses
 * how to import (ImportMapping) and the import waits for its
 * configuration.
 *
 * Like the configuration sync, a plain service that writes the import it
 * works on itself: one flush per batch, so that the progress shows.
 */
final class ImportAnalyser
{
    public const string FIRST_PHASE = 'read';

    /** How long a batch reads before it hands over. */
    private const int SECONDS = 15;

    public function __construct(
        private readonly ImportsDirectory $directory,
        private readonly ConfigRegistry $config,
        private readonly TrackerRepository $trackers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Reads one batch.
     *
     * @return bool whether there is more to read
     */
    public function advance(Import $import): bool
    {
        $archive = $this->directory->open($import);
        if (self::FIRST_PHASE === $import->getPhase()) {
            $import->recordAnalysis([], $archive->manifest()->toArray());
            $import->move('scan', 0, 0, $archive->issuesSize());
            $this->entityManager->flush();

            return true;
        }

        $manifest = ImportManifest::fromArray($import->getManifest());
        $analysis = ImportAnalysis::fromArray($import->getAnalysis());
        $until = $this->clock->now()->modify('+'.self::SECONDS.' seconds');
        $position = $import->getPosition();
        $done = $import->getDone();
        $finished = true;
        foreach ($archive->issues($manifest, $position) as $next => $issue) {
            foreach ($issue->allFiles() as $file) {
                if (!$archive->hasFile($file['path'])) {
                    throw ImportArchiveException::missingFile($issue->id, $file['path']);
                }
            }
            $analysis->count($issue);
            $position = $next;
            ++$done;
            if ($this->clock->now() >= $until) {
                $finished = false;
                break;
            }
        }

        $import->recordAnalysis($analysis->toArray());
        $import->move('scan', $position, $done, $import->getTotal());
        if ($finished) {
            $existing = $this->trackers->findByProject($import->getProject(), includeInactive: true);
            $import->finishAnalysis(ImportMapping::guess($manifest, $analysis, $this->config->definition(), $existing)->toArray());
        }
        $this->entityManager->flush();

        return !$finished;
    }
}

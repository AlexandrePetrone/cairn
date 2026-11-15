<?php

declare(strict_types=1);

namespace App\Import;

use App\Config\ConfigRegistry;
use App\Entity\Import;
use App\Entity\Tracker;
use App\MessageHandler\Command\Import\ImportException;
use App\Repository\TrackerRepository;

/**
 * Checks what the run of an import could not make up for: every tracker
 * goes to a tracker of the project, to a workflow that exists, or
 * nowhere, and every place is one of its workflow. The other choices
 * (fields, priorities, link types, roles) are those the forms offer, and
 * the run absorbs one that no longer holds: the value ends the
 * description, the criticality is the default, the link is left out, the
 * role is refused and listed in the report. StartImport checks again,
 * and that nothing is left to choose (`complete`), since the instance may
 * have changed in between.
 */
final class ImportMappingChecker
{
    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly TrackerRepository $trackers,
    ) {
    }

    public function check(Import $import, ImportMapping $mapping, bool $complete = false): void
    {
        $definition = $this->config->definition();
        $manifest = ImportManifest::fromArray($import->getManifest());
        $analysis = ImportAnalysis::fromArray($import->getAnalysis());
        $existing = $this->trackers->findByProject($import->getProject(), includeArchived: true);
        $existingTargets = array_map(static fn (Tracker $tracker): string => 'tracker:'.$tracker->getId(), $existing);

        foreach ($mapping->trackers as $id => $tracker) {
            $label = $manifest->trackers[$id]['label'] ?? throw ImportException::invalidChoice('tracker', (string) $id);
            $target = (string) ($tracker['target'] ?? '');
            $valid = ImportMapping::SKIP === $target
                || \in_array($target, $existingTargets, true)
                || (str_starts_with($target, 'new:') && $definition->hasWorkflow(substr($target, 4)) && '' !== trim((string) ($tracker['name'] ?? '')) && mb_strlen((string) $tracker['name']) <= 120);
            if (!$valid) {
                throw ImportException::invalidChoice($label, $target);
            }
        }

        foreach ($mapping->places as $workflowId => $sources) {
            if (!$definition->hasWorkflow((string) $workflowId)) {
                throw ImportException::invalidChoice('workflow', (string) $workflowId);
            }
            foreach ($sources as $source => $statuses) {
                foreach ($statuses as $statusId => $place) {
                    $label = $manifest->workflows[$source]['statuses'][$statusId]['label'] ?? throw ImportException::invalidChoice('status', (string) $statusId);
                    if (!$definition->workflow((string) $workflowId)->hasPlace((string) $place)) {
                        throw ImportException::invalidChoice($label, (string) $place);
                    }
                }
            }
        }

        if (!$complete) {
            return;
        }
        if (0 === $analysis->totals($mapping, $existing)['issues']) {
            throw ImportException::nothingToImport();
        }
        foreach ($mapping->statusesInUse($manifest, $analysis, $existing) as $workflowId => $sources) {
            foreach ($sources as $source => $statuses) {
                foreach ($statuses as $statusId) {
                    if (!$definition->hasWorkflow($workflowId) || !$definition->workflow($workflowId)->hasPlace($mapping->places[$workflowId][$source][$statusId] ?? '')) {
                        throw ImportException::invalidChoice($manifest->workflows[$source]['statuses'][$statusId]['label'], '');
                    }
                }
            }
        }
    }
}

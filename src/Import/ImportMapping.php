<?php

declare(strict_types=1);

namespace App\Import;

use App\Config\Definition\InstanceDefinition;
use App\Config\FieldType;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Tracker;

/**
 * How an import turns its archive into Cairn: whether closed issues,
 * files and versions are taken; each tracker into a tracker of the
 * project (existing, to create on a workflow, or left out); each status
 * into a place of that workflow; each field into a field of Cairn, a
 * line at the end of the description or nothing; each priority into a
 * criticality; each link type into Cairn's, or nothing;
 * and for each person without an account, whether theirs is created
 * active, and their role on the project. Guessed from the archive
 * (`guess()`), changed by the administrator (ConfigureImport), read by
 * the run. Kept on the import as an array.
 */
final readonly class ImportMapping
{
    public const string SKIP = 'skip';
    public const string IGNORE = 'ignore';
    public const string APPEND = 'append';

    /**
     * @param array<array-key, array{target: string, name: string}>        $trackers   tracker id => `skip`, `new:<workflow>` or `tracker:<id>`, with the name of a new one
     * @param array<array-key, array<array-key, array<array-key, string>>> $places     Cairn workflow => archive workflow => status id => place id
     * @param array<array-key, string>                                     $fields     field id => `ignore`, `append` or `field:<id>`
     * @param array<array-key, string>                                     $priorities priority id => Criticality value
     * @param array<array-key, string|null>                                $links      link type id => link type of Cairn, null to leave them out
     * @param array<array-key, array{active: bool, role: string}>          $people     person id => whether a new account is active, and their role on the project, '' for none
     */
    public function __construct(
        public bool $closed = true,
        public bool $files = true,
        public bool $versions = true,
        public array $trackers = [],
        public array $places = [],
        public array $fields = [],
        public array $priorities = [],
        public array $links = [],
        public array $people = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['closed'] ?? true),
            (bool) ($data['files'] ?? true),
            (bool) ($data['versions'] ?? true),
            (array) ($data['trackers'] ?? []),
            (array) ($data['places'] ?? []),
            (array) ($data['fields'] ?? []),
            (array) ($data['priorities'] ?? []),
            (array) ($data['links'] ?? []),
            (array) ($data['people'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * A copy with the given parts replaced.
     *
     * @param array<string, mixed> $changes property => new value
     */
    public function with(array $changes): self
    {
        return self::fromArray($changes + $this->toArray());
    }

    /**
     * What the import would do, guessed from the names: a tracker goes
     * to the tracker of the project that has its name, or to a new one on
     * the workflow that resembles it; a status to the place of the same
     * label, a closed one to the first closed place; a field, a link type
     * to those of the same label. Nobody gets an active
     * account unless the administrator says so.
     *
     * @param list<Tracker> $existingTrackers the trackers of the target project
     */
    public static function guess(ImportManifest $manifest, ImportAnalysis $analysis, InstanceDefinition $definition, array $existingTrackers): self
    {
        $workflowIds = array_keys($definition->workflows);
        $trackers = [];
        foreach ($manifest->trackers as $id => $tracker) {
            $existing = array_find($existingTrackers, static fn (Tracker $candidate): bool => self::same($candidate->getName(), $tracker['label']));
            $workflow = array_find($workflowIds, static fn (string $workflowId): bool => self::resembles($tracker['label'], $workflowId) || self::resembles($tracker['label'], $definition->workflow($workflowId)->label));
            $trackers[$id] = [
                'target' => match (true) {
                    0 === $analysis->issuesOf((string) $id) => self::SKIP,
                    null !== $existing => 'tracker:'.$existing->getId(),
                    default => 'new:'.($workflow ?? $workflowIds[0] ?? ''),
                },
                'name' => mb_substr($tracker['label'], 0, 120),
            ];
        }

        $fields = [];
        foreach ($manifest->fields as $id => $field) {
            $match = array_find($definition->fields, static fn ($candidate): bool => self::fits($field['type'], $candidate->type) && (self::same($candidate->label, $field['label']) || self::same($candidate->id, (string) $id)));
            $fields[$id] = null !== $match ? 'field:'.$match->id : self::APPEND;
        }

        $priorities = [];
        $count = \count($manifest->priorities);
        foreach (array_keys($manifest->priorities) as $index => $id) {
            $criticality = match (true) {
                $count >= 4 && $index === $count - 1 => Criticality::Blocking,
                $count >= 3 && $index === $count - ($count >= 4 ? 2 : 1) => Criticality::High,
                $count >= 2 && 0 === $index => Criticality::Low,
                default => Criticality::Normal,
            };
            $priorities[$id] = $criticality->value;
        }

        $links = [];
        foreach ($manifest->linkTypes as $id => $label) {
            $links[$id] = array_find($definition->links, static fn ($link): bool => self::same($link->label, $label) || self::same($link->id, (string) $id))?->id;
        }

        return new self(trackers: $trackers, fields: $fields, priorities: $priorities, links: $links)->withGuessedPlaces($manifest, $analysis, $definition, $existingTrackers);
    }

    /**
     * A copy where every status in use has a place: those already chosen
     * are kept, the others are guessed. Called again when the trackers
     * change, since new workflows may then be in use.
     *
     * @param list<Tracker> $existingTrackers
     */
    public function withGuessedPlaces(ImportManifest $manifest, ImportAnalysis $analysis, InstanceDefinition $definition, array $existingTrackers): self
    {
        $places = [];
        foreach ($this->statusesInUse($manifest, $analysis, $existingTrackers) as $workflowId => $sources) {
            if (!$definition->hasWorkflow($workflowId)) {
                continue;
            }
            $workflow = $definition->workflow($workflowId);
            foreach ($sources as $source => $statuses) {
                foreach ($statuses as $statusId) {
                    $status = $manifest->workflows[$source]['statuses'][$statusId];
                    $chosen = $this->places[$workflowId][$source][$statusId] ?? '';
                    $offered = $definition->placesOf($workflow);
                    $places[$workflowId][$source][$statusId] = $workflow->hasPlace($chosen) ? $chosen : (
                        array_find($offered, static fn ($place): bool => self::same($place->label, $status['label']) || self::same($place->id, (string) $statusId))
                        ?? ($status['closed'] ? array_find($offered, static fn ($place): bool => PlaceStatus::Closed === $place->status) : null)
                    )->id ?? $workflow->initial;
                }
            }
        }

        return $this->with(['places' => $places]);
    }

    /**
     * The workflow of Cairn a tracker of the archive leads to, null when
     * it is left out.
     *
     * @param list<Tracker> $existingTrackers
     */
    public function workflowOf(string $trackerId, array $existingTrackers): ?string
    {
        $target = $this->trackers[$trackerId]['target'] ?? self::SKIP;
        if (str_starts_with($target, 'new:')) {
            return substr($target, 4);
        }
        if (str_starts_with($target, 'tracker:')) {
            $id = (int) substr($target, 8);

            return array_find($existingTrackers, static fn (Tracker $tracker): bool => $tracker->getId() === $id)?->getWorkflowId();
        }

        return null;
    }

    /**
     * The statuses the imported issues have, by the workflow of Cairn
     * they go through and their workflow in the archive.
     *
     * @param list<Tracker> $existingTrackers
     *
     * @return array<array-key, array<array-key, list<string>>> Cairn workflow => archive workflow => status ids, in the archive's order
     */
    public function statusesInUse(ImportManifest $manifest, ImportAnalysis $analysis, array $existingTrackers): array
    {
        $used = [];
        foreach ($manifest->trackers as $trackerId => $tracker) {
            $workflow = $this->workflowOf((string) $trackerId, $existingTrackers);
            if (null === $workflow) {
                continue;
            }
            foreach (array_keys($analysis->statuses[$trackerId] ?? []) as $statusId) {
                if ($this->closed || !$manifest->workflows[$tracker['workflow']]['statuses'][$statusId]['closed']) {
                    $used[$workflow][$tracker['workflow']][(string) $statusId] = true;
                }
            }
        }
        $ordered = [];
        foreach ($used as $workflow => $sources) {
            foreach ($sources as $source => $statuses) {
                $ordered[$workflow][$source] = array_values(array_filter(array_map(strval(...), array_keys($manifest->workflows[$source]['statuses'])), static fn (string $id): bool => isset($statuses[$id])));
            }
        }

        return $ordered;
    }

    /**
     * Whether the import takes the issue: its tracker goes somewhere,
     * and it is open or closed issues are taken.
     *
     * @param list<Tracker> $existingTrackers
     */
    public function takes(ArchiveIssue $issue, array $existingTrackers): bool
    {
        return ($this->closed || !$issue->closed) && null !== $this->workflowOf($issue->tracker, $existingTrackers);
    }

    /** Whether the account created for a person is active. */
    public function isActive(string $personId): bool
    {
        return true === ($this->people[$personId]['active'] ?? false);
    }

    /** The role a person gets on the project, null for none. */
    public function roleOf(string $personId): ?string
    {
        $role = $this->people[$personId]['role'] ?? '';

        return '' === $role ? null : $role;
    }

    /**
     * The role offered at first to a person: the role of Cairn that has
     * the label of theirs in the archive, '' when there is none. A new
     * account is external, so a role for internal users only is offered
     * to an account that already is internal, and to nobody else.
     */
    public static function guessedRole(?string $archiveRole, InstanceDefinition $definition, bool $internal): string
    {
        $role = null === $archiveRole ? null : array_find($definition->roles, static fn ($role): bool => self::same($role->label, $archiveRole) || self::same($role->id, $archiveRole));

        return null === $role || ($role->internalOnly && !$internal) ? '' : $role->id;
    }

    /**
     * Whether a value of a type of the archive fits a field of Cairn: a
     * text field takes anything.
     */
    public static function fits(string $type, FieldType $field): bool
    {
        return match ($field) {
            FieldType::Text, FieldType::Textarea, FieldType::RichText => true,
            FieldType::Number => 'number' === $type,
            FieldType::Date => 'date' === $type,
            FieldType::Url => 'link' === $type,
            FieldType::Bool => 'boolean' === $type,
            FieldType::Select, FieldType::Choice => 'list' === $type,
        };
    }

    private static function same(string $a, string $b): bool
    {
        return '' !== trim($a) && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /** A tracker name speaks of a workflow: "Bugs" for `bug`. */
    private static function resembles(string $name, string $workflow): bool
    {
        $name = mb_strtolower(trim($name));
        $workflow = mb_strtolower(trim($workflow));

        return '' !== $workflow && '' !== $name && (str_contains($name, $workflow) || str_contains($workflow, $name));
    }
}

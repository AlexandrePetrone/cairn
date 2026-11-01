<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * Places, transitions and fields of one workflow. Trackers (in database)
 * reference a workflow by id.
 */
final readonly class WorkflowDefinition
{
    /** @var array<string, PlaceDefinition> id => place, in declaration order */
    public array $places;

    /** @var array<string, TransitionDefinition> id => transition */
    public array $transitions;

    /** @var array<string, FieldUsage> field id => usage, in declaration order */
    public array $fields;

    /**
     * @param string                     $initial       place of a newly created issue
     * @param list<PlaceDefinition>      $places
     * @param list<TransitionDefinition> $transitions
     * @param list<FieldUsage>           $fields
     * @param string|null                $fallbackPlace where orphaned issues go under the Fallback migration policy
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $initial,
        array $places,
        array $transitions,
        array $fields = [],
        public ?string $fallbackPlace = null,
    ) {
        Definitions::assertId($id, 'workflows');
        Definitions::assertLabel($label, "workflows.$id.label");
        $this->places = Definitions::indexById($places, PlaceDefinition::class, "workflows.$id.places");
        $this->transitions = Definitions::indexById($transitions, TransitionDefinition::class, "workflows.$id.transitions");
        $this->fields = Definitions::indexById($fields, FieldUsage::class, "workflows.$id.fields", 'field');
    }

    public function hasPlace(string $id): bool
    {
        return isset($this->places[$id]);
    }

    /**
     * The place, or null when it left the configuration (an issue may
     * still sit on it until the sync migrates it).
     */
    public function findPlace(string $id): ?PlaceDefinition
    {
        return $this->places[$id] ?? null;
    }

    public function place(string $id): PlaceDefinition
    {
        return $this->places[$id] ?? throw new InvalidDefinitionException(\sprintf('workflows.%s: unknown place "%s".', $this->id, $id));
    }

    /** @return list<string> */
    public function placeIds(): array
    {
        return array_keys($this->places);
    }

    public function transition(string $id): TransitionDefinition
    {
        return $this->transitions[$id] ?? throw new InvalidDefinitionException(\sprintf('workflows.%s: unknown transition "%s".', $this->id, $id));
    }

    /**
     * Transitions available from a place, in declaration order.
     *
     * @return list<TransitionDefinition>
     */
    public function transitionsFrom(string $placeId): array
    {
        return array_values(array_filter($this->transitions, static fn (TransitionDefinition $t) => \in_array($placeId, $t->from, true)));
    }

    /**
     * The shortest sequence of transitions from the initial place to a
     * place, whoever may trigger them: how fixtures put an issue there.
     *
     * @return list<TransitionDefinition>
     */
    public function pathTo(string $placeId): array
    {
        $paths = [$this->initial => []];
        $queue = [$this->initial];
        while ([] !== $queue) {
            $place = array_shift($queue);
            if ($place === $placeId) {
                return $paths[$place];
            }
            foreach ($this->transitionsFrom($place) as $transition) {
                if (!isset($paths[$transition->to])) {
                    $paths[$transition->to] = [...$paths[$place], $transition];
                    $queue[] = $transition->to;
                }
            }
        }

        throw new InvalidDefinitionException(\sprintf('workflows.%s: place "%s" cannot be reached from "%s".', $this->id, $placeId, $this->initial));
    }

    /**
     * The place that declares a removed place in its `migrateFrom`, if any.
     */
    public function migrationTargetFor(string $removedPlaceId): ?string
    {
        foreach ($this->places as $place) {
            if (\in_array($removedPlaceId, $place->migrateFrom, true)) {
                return $place->id;
            }
        }

        return null;
    }
}

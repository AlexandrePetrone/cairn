<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * Places, transitions and fields of one workflow. Trackers (in database)
 * reference a workflow by id. The places are those of the instance, named
 * by id: InstanceDefinition::place() gives what one says.
 */
final readonly class WorkflowDefinition
{
    /** @var list<string> ids of places of the instance, in the order of the kanban columns */
    public array $places;

    /** @var array<string, string> place this workflow no longer has => place of it that takes its issues on sync */
    public array $migrations;

    /** @var array<string, TransitionDefinition> id => transition */
    public array $transitions;

    /** @var array<string, FieldUsage> field id => usage, in declaration order */
    public array $fields;

    /**
     * @param string                     $initial       place of a newly created issue
     * @param list<string>               $places        ids of places of the instance
     * @param list<TransitionDefinition> $transitions
     * @param list<FieldUsage>           $fields
     * @param string|null                $fallbackPlace where orphaned issues go under the Fallback migration policy
     * @param array<string, string>      $migrations    removed place id => id of the place that takes its issues
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $initial,
        array $places,
        array $transitions,
        array $fields = [],
        public ?string $fallbackPlace = null,
        array $migrations = [],
    ) {
        Definitions::assertId($id, 'workflows');
        Definitions::assertLabel($label, "workflows.$id.label");
        $this->places = Definitions::assertIdList($places, "workflows.$id.places");
        $this->transitions = Definitions::indexById($transitions, TransitionDefinition::class, "workflows.$id.transitions");
        $this->fields = Definitions::indexById($fields, FieldUsage::class, "workflows.$id.fields", 'field');

        foreach ($migrations as $from => $to) {
            if (!\is_string($from) || !\is_string($to)) {
                throw new InvalidDefinitionException("workflows.$id.migrations: expected a map of removed place id => place id.");
            }
        }
        $this->migrations = $migrations;
    }

    public function hasPlace(string $id): bool
    {
        return \in_array($id, $this->places, true);
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
     * The place that takes the issues of one this workflow no longer has, if any.
     */
    public function migrationTargetFor(string $removedPlaceId): ?string
    {
        return $this->migrations[$removedPlaceId] ?? null;
    }
}

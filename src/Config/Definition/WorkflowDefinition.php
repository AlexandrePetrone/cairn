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
    /** @var array<string, TransitionDefinition> id => transition */
    public array $transitions;

    /** @var array<string, FieldUsage> field id => usage, in the order of the form */
    public array $fields;

    /**
     * @param string                     $initial     place of a newly created issue
     * @param list<string>               $places      ids of places of the instance, in the order of the kanban columns
     * @param list<TransitionDefinition> $transitions
     * @param list<FieldUsage>           $fields
     * @param array<string, string>      $aside       those of its places that stand beside the path its issues follow, each with the place of the path it stands beside, for the diagram
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $initial,
        public array $places,
        array $transitions,
        array $fields = [],
        public array $aside = [],
    ) {
        $this->transitions = Definitions::indexById($transitions, "workflows.$id.transitions");
        $this->fields = Definitions::indexById($fields, "workflows.$id.fields", 'field');
    }

    public function hasPlace(string $id): bool
    {
        return \in_array($id, $this->places, true);
    }

    /**
     * The path the issues of the workflow follow: its places in their
     * order, without those that stand beside one of them. Nobody is held
     * to it; the diagram draws it.
     *
     * @return list<string>
     */
    public function path(): array
    {
        return array_values(array_filter($this->places, fn (string $place): bool => !isset($this->aside[$place])));
    }

    /**
     * The places an issue goes through to stand on one, the initial one
     * left out: the path up to it, or up to the place it stands beside and
     * then itself. How the fixtures put an issue there, since nobody is
     * held to the path.
     *
     * @return list<string>
     */
    public function trailTo(string $placeId): array
    {
        if (isset($this->aside[$placeId])) {
            return [...$this->trailTo($this->aside[$placeId]), $placeId];
        }
        $path = $this->path();
        $reached = array_search($placeId, $path, true);
        if (false === $reached) {
            throw new InvalidDefinitionException(\sprintf('workflows.%s: unknown place "%s".', $this->id, $placeId));
        }
        $start = (int) array_search($this->initial, $path, true);

        // A place the kanban sets before the initial one is reached at once.
        return $reached < $start ? [$placeId] : \array_slice($path, $start + 1, $reached - $start);
    }

    public function transition(string $id): TransitionDefinition
    {
        return $this->transitions[$id] ?? throw new InvalidDefinitionException(\sprintf('workflows.%s: unknown transition "%s".', $this->id, $id));
    }

    /**
     * Transitions available from a place, in the order of the workflow.
     *
     * @return list<TransitionDefinition>
     */
    public function transitionsFrom(string $placeId): array
    {
        return array_values(array_filter($this->transitions, static fn (TransitionDefinition $t) => \in_array($placeId, $t->from, true)));
    }
}

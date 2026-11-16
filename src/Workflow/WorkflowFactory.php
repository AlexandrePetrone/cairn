<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Builds a Symfony state machine from a workflow definition of the instance
 * configuration. The state machine enforces the structure (which transition
 * leaves which place) and dispatches the workflow events; who may trigger a
 * transition is decided by AccessControl, not here. Subjects implement
 * HasPlace.
 */
final class WorkflowFactory
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param array<string, PlaceDefinition> $places those of the instance, by id
     */
    public function create(WorkflowDefinition $definition, array $places): WorkflowInterface
    {
        $builder = (new DefinitionBuilder())
            ->addPlaces($definition->places)
            ->setInitialPlaces($definition->initial);

        // A state machine transition has a single source place: a configured
        // transition with several sources becomes as many transitions with
        // the same name.
        foreach ($definition->transitions as $transition) {
            foreach ($transition->from as $from) {
                $builder->addTransition(new Transition($transition->id, $from, $transition->to));
            }
        }

        return new StateMachine(
            $builder->build(),
            new PlaceMarkingStore($places),
            $this->dispatcher,
            $definition->id,
        );
    }
}

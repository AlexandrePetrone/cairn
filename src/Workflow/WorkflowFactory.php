<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\WorkflowDefinition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Builds a Symfony state machine from a workflow definition of the instance
 * configuration. The state machine enforces the structure (which transition
 * leaves which place) and dispatches the workflow events; who may trigger a
 * transition is decided by the application, not here.
 */
final class WorkflowFactory
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function create(WorkflowDefinition $definition): WorkflowInterface
    {
        $builder = (new DefinitionBuilder())
            ->addPlaces($definition->placeIds())
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
            new MethodMarkingStore(singleState: true, property: 'place'),
            $this->dispatcher,
            $definition->id,
        );
    }
}

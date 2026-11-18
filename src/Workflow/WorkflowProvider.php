<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\ConfigRegistry;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Issue;
use App\Entity\Place;
use App\Repository\PlaceRepository;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The workflows of the configuration in force, built on first use and kept
 * as long as the definition they were built from is the one ConfigRegistry
 * gives: a change of the configuration, made by this process or read at
 * its next request, builds them again. Trackers reference them by id.
 */
final class WorkflowProvider
{
    /** @var array<string, WorkflowInterface> */
    private array $workflows = [];

    /** The definition the workflows kept were built from. */
    private ?InstanceDefinition $builtFrom = null;

    public function __construct(
        private readonly ConfigRegistry $registry,
        private readonly WorkflowFactory $factory,
        private readonly PlaceRepository $rows,
    ) {
    }

    public function get(string $workflowId): WorkflowInterface
    {
        $definition = $this->registry->definition();
        if ($definition !== $this->builtFrom) {
            $this->workflows = [];
            $this->builtFrom = $definition;
        }

        return $this->workflows[$workflowId] ??= $this->factory->create($definition->workflow($workflowId), $this->row(...));
    }

    /**
     * The row of a place, which an issue that moves there points to. What
     * the place says is read from its definition; the row is what the
     * database ties the issue to.
     */
    public function row(PlaceDefinition|string $place): Place
    {
        $id = $place instanceof PlaceDefinition ? $place->id : $place;

        return $this->rows->find($id) ?? throw new \LogicException(\sprintf('The place "%s" of the configuration has a row.', $id));
    }

    public function definition(string $workflowId): WorkflowDefinition
    {
        return $this->registry->definition()->workflow($workflowId);
    }

    /**
     * The places of a workflow, in its order.
     *
     * @return list<PlaceDefinition>
     */
    public function places(string $workflowId): array
    {
        return $this->registry->definition()->placesOf($this->definition($workflowId));
    }

    public function place(string $placeId): PlaceDefinition
    {
        return $this->registry->definition()->place($placeId);
    }

    public function findPlace(string $placeId): ?PlaceDefinition
    {
        return $this->registry->definition()->findPlace($placeId);
    }

    /**
     * The place an issue sits on. It is always one of the instance, which
     * the database holds it to; it may no longer be one of the workflow of
     * its tracker, when it was moved there in the instant the place left it.
     */
    public function placeOf(Issue $issue): PlaceDefinition
    {
        return $this->place($issue->getPlace());
    }
}

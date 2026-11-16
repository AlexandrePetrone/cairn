<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\ConfigRegistry;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Issue;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The workflows of the configuration in force, built on first use and kept
 * for the rest of the process. Trackers reference them by id.
 */
final class WorkflowProvider
{
    /** @var array<string, WorkflowInterface> */
    private array $workflows = [];

    public function __construct(
        private readonly ConfigRegistry $registry,
        private readonly WorkflowFactory $factory,
    ) {
    }

    public function get(string $workflowId): WorkflowInterface
    {
        return $this->workflows[$workflowId] ??= $this->factory->create($this->definition($workflowId), $this->registry->definition()->places);
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
     * The place an issue sits on, null when the configuration no longer
     * has it.
     */
    public function placeOf(Issue $issue): ?PlaceDefinition
    {
        return $this->findPlace($issue->getPlace());
    }
}

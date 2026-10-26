<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\WorkflowDefinition;
use App\Config\Snapshot\ConfigRegistry;
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
        return $this->workflows[$workflowId] ??= $this->factory->create($this->definition($workflowId));
    }

    public function definition(string $workflowId): WorkflowDefinition
    {
        return $this->registry->definition()->workflow($workflowId);
    }
}

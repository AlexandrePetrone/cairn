<?php

declare(strict_types=1);

namespace App\Twig\Components\Workflow;

use App\Config\ConfigRegistry;
use App\Config\Definition\WorkflowDefinition;
use App\Workflow\MermaidDiagram;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A workflow drawn as a graph: the Mermaid source is written in the page,
 * the `workflow-diagram` controller draws it. Without JavaScript the source
 * stays, and the table of actions next to it tells the same.
 */
#[AsTwigComponent('Workflow:Diagram')]
final class Diagram
{
    public WorkflowDefinition $workflow;

    public function __construct(
        private readonly MermaidDiagram $diagram,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function getSource(): string
    {
        return $this->diagram->render($this->workflow, $this->config->definition()->placesOf($this->workflow));
    }
}

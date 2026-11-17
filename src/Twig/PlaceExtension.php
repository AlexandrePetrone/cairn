<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Issue;
use App\Workflow\WorkflowProvider;
use Twig\Attribute\AsTwigFunction;

/**
 * Labels of workflow places come from the configuration in force, not from
 * the issue, which only stores the place id.
 */
final class PlaceExtension
{
    public function __construct(
        private readonly WorkflowProvider $workflows,
    ) {
    }

    /**
     * The label of the issue's place.
     */
    #[AsTwigFunction('issue_place')]
    public function issuePlace(Issue $issue): string
    {
        return $this->workflows->placeOf($issue)->label;
    }
}

<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Issue;
use App\Workflow\WorkflowProvider;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The top of the issue page, redrawn when another component of the page
 * changes the issue (`issue:changed`): privacy and place hint follow.
 */
#[AsLiveComponent('Issue:Header')]
final class Header
{
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    public function __construct(
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function getPlace(): ?PlaceDefinition
    {
        $workflow = $this->workflows->definition($this->issue->getTracker()->getWorkflowId());
        $place = (string) $this->issue->getPlace();

        return $workflow->hasPlace($place) ? $workflow->place($place) : null;
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}

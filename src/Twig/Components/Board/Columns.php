<?php

declare(strict_types=1);

namespace App\Twig\Components\Board;

use App\Config\Definition\PlaceDefinition;
use App\Form\IssueListFilters;
use App\Twig\Components\IssueList\ListScope;
use App\Workflow\WorkflowProvider;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The kanban of a tracker, the other view of its list: one column per
 * place of its workflow, filtered like the list, and the component that
 * moves cards. It loads no issue itself: each Board:Column does when it
 * shows, so the board is never read whole.
 */
#[AsTwigComponent('Board:Columns')]
final class Columns
{
    public ListScope $scope;

    public IssueListFilters $filters;

    public function __construct(
        private readonly WorkflowProvider $workflows,
    ) {
    }

    /**
     * @return list<PlaceDefinition>
     */
    public function getPlaces(): array
    {
        $tracker = $this->scope->tracker ?? throw new \LogicException('Only the list of a tracker has a kanban.');

        return array_values($this->workflows->definition($tracker->getWorkflowId())->places);
    }
}

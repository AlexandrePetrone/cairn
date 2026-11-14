<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Form\Issue\IssueListFilters;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The bar above an issue list, shared by its list and its kanban: a GET
 * form whose controls are the parameters of the address. It stays out of
 * the frame the results load in, so it is drawn once: a change submits it
 * to that frame (the `filters` controller) and the menus stay as the user
 * left them. The choices come from ListChoices.
 */
#[AsTwigComponent('IssueList:Filters')]
final class Filters
{
    public ListScope $scope;

    public IssueListFilters $filters;

    /** The heading of the page, when the bar carries it: the views of a tracker then sit on its line. */
    public ?string $title = null;

    public function __construct(
        public readonly ListChoices $choices,
    ) {
    }

    public function getView(): string
    {
        return $this->scope->view($this->filters);
    }
}

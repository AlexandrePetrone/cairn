<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Issue;
use App\Message\Query\Budget\IssueBudgetLines;
use App\Message\Query\Budget\ListIssueBudgetLines;
use App\Message\QueryBus;
use App\Security\Viewer;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The lines of the budgets that name the issue, a card of its side panel,
 * read-only: lines are written on the pages of the budgets. Shown to
 * whoever may read the budgets of the project, and only when a line names
 * the issue. It acts on nothing, and is redrawn on `issue:changed`: an
 * automation rule may mark the lines when an action of the workflow is
 * applied on the page. The query checks who reads at each draw.
 */
#[AsLiveComponent('Issue:Budget')]
final class Budget
{
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    /** Read once per render. */
    private ?IssueBudgetLines $lines = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
    ) {
    }

    public function getLines(): IssueBudgetLines
    {
        return $this->lines ??= $this->queries->ask(new ListIssueBudgetLines($this->viewer->get(), $this->issue));
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
        $this->lines = null;
    }

    /**
     * The time the issue stands for, in minutes: its approved lines and
     * those waiting for an approval. A refused line counts for nothing, nor
     * does a line to estimate, whose quantity is a draft.
     */
    public function getTotal(): int
    {
        $counted = array_filter($this->getLines()->lines, static fn (BudgetLine $line): bool => \in_array($line->getStatus(), [BudgetLineStatus::Approved, BudgetLineStatus::Pending], true));

        return array_sum(array_map(static fn (BudgetLine $line): int => (int) $line->getQuantity(), $counted));
    }
}

<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Issue;
use App\Message\Query\Budget\ListIssueBudgetLines;
use App\Message\QueryBus;
use App\Security\Viewer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The lines of the budgets that name the issue, a card of its side panel,
 * read-only: lines are written on the pages of the budgets. Shown to
 * whoever may read the budgets of the project, and only when a line names
 * the issue. Not a live component: nothing done on the page of an issue
 * changes a budget.
 */
#[AsTwigComponent('Issue:Budget')]
final class Budget
{
    public Issue $issue;

    /** @var list<BudgetLine>|null read once per render */
    private ?array $lines = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * @return list<BudgetLine>
     */
    public function getLines(): array
    {
        return $this->lines ??= $this->queries->ask(new ListIssueBudgetLines($this->viewer->get(), $this->issue));
    }

    /**
     * The time the issue stands for, in minutes: its approved lines and
     * those waiting for an approval. A refused line counts for nothing, a
     * line to estimate has no quantity yet.
     */
    public function getTotal(): int
    {
        $counted = array_filter($this->getLines(), static fn (BudgetLine $line): bool => \in_array($line->getStatus(), [BudgetLineStatus::Approved, BudgetLineStatus::Pending], true));

        return array_sum(array_map(static fn (BudgetLine $line): int => (int) $line->getQuantity(), $counted));
    }
}

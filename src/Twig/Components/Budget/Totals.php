<?php

declare(strict_types=1);

namespace App\Twig\Components\Budget;

use App\Entity\Project;
use App\Message\Query\Budget\BudgetSummary;
use App\Message\Query\Budget\ListBudgets;
use App\Message\QueryBus;
use App\Security\AccessControl;
use App\Security\Viewer;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * What the open budgets of a project amount to together, at the top of
 * the budgets page: their volume, what was consumed, what is left and
 * what waits for an approval. A closed budget is history and counts for
 * none of it. Redrawn when a card of the page changed a line
 * (`budget:changed`).
 */
#[AsLiveComponent('Budget:Totals')]
final class Totals
{
    use DefaultActionTrait;

    #[LiveProp]
    public Project $project;

    /** @var list<BudgetSummary>|null the open budgets, read once per render */
    private ?array $open = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
    ) {
    }

    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->access->canReadBudgets($this->viewer->get(), $this->project)) {
            throw new AccessDeniedException('Reading the budgets needs budget.view.');
        }
    }

    public function getCount(): int
    {
        return \count($this->open());
    }

    public function getVolume(): int
    {
        return array_sum(array_map(static fn (BudgetSummary $summary): int => $summary->budget->getVolume(), $this->open()));
    }

    public function getConsumed(): int
    {
        return array_sum(array_map(static fn (BudgetSummary $summary): int => $summary->consumed, $this->open()));
    }

    public function getRemaining(): int
    {
        return $this->getVolume() - $this->getConsumed();
    }

    public function getPending(): int
    {
        return array_sum(array_map(static fn (BudgetSummary $summary): int => $summary->pending, $this->open()));
    }

    #[LiveListener('budget:changed')]
    public function refresh(): void
    {
        $this->open = null;
    }

    /**
     * @return list<BudgetSummary>
     */
    private function open(): array
    {
        return $this->open ??= array_values(array_filter(
            $this->queries->ask(new ListBudgets($this->project)),
            static fn (BudgetSummary $summary): bool => $summary->budget->isOpen(),
        ));
    }
}

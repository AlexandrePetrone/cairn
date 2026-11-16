<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Budget;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Message\Command\Project\UpdateProject;
use App\Message\Query\Budget\BudgetStatement;
use App\Message\Query\Budget\BudgetSummary;
use App\Message\Query\Budget\ListBudgets;
use App\Message\Query\Budget\ListIssueBudgetLines;
use App\Message\Query\Budget\ShowBudget;
use App\Message\QueryBus;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ListBudgets, ShowBudget and ListIssueBudgetLines on the shop of the
 * scene, which counts in days: an order of ten days and one of five.
 * MD-SPT-8 is a feature, approved for two days, one on each order;
 * MD-SPT-7 waits for a day and a half on the second; MD-SPT-6, an
 * internal task a client does not see, has half a day approved there, and
 * a line names no issue. Thomas manages, Claire is the client.
 */
final class BudgetQueriesTest extends KernelTestCase
{
    use KernelHelpers;

    private ShopScene $scene;
    private Budget $first;
    private Budget $second;
    private BudgetLine $internal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->first = $this->scene->budget('Order 2026-01', 10);
        $this->second = $this->scene->budget('Order 2026-10', 5);

        $this->scene->line($this->first, BudgetLineStatus::Approved, 1, 'MD-SPT-8');
        $this->scene->line($this->second, BudgetLineStatus::Approved, 1, 'MD-SPT-8', 'Second format');
        $this->scene->line($this->second, BudgetLineStatus::Pending, 1.5, 'MD-SPT-7');
        $this->internal = $this->scene->line($this->second, BudgetLineStatus::Approved, 0.5, 'MD-SPT-6');
        $this->scene->line($this->second, BudgetLineStatus::Refused, 3, label: 'Rewrite of the checkout');
        $this->scene->line($this->second, BudgetLineStatus::ToEstimate, null, label: 'Audit of the performance');
    }

    public function testEachBudgetSaysWhatItConsumedAndWhatWaits(): void
    {
        $summaries = self::getContainer()->get(QueryBus::class)->ask(new ListBudgets($this->scene->shop));

        self::assertSame(['Order 2026-01', 'Order 2026-10'], array_map(static fn (BudgetSummary $summary): string => $summary->budget->getName(), $summaries));
        [$first, $second] = $summaries;
        self::assertSame([420, 0, 1, 3780], [$first->consumed, $first->pending, $first->lines, $first->remaining()]);
        self::assertSame([630, 630, 5, 1470], [$second->consumed, $second->pending, $second->lines, $second->remaining()], 'only the approved lines are consumed: neither the refused one nor the one to estimate');
    }

    public function testTheStatementShowsEveryLineToEveryReader(): void
    {
        $forThomas = $this->statement('thomas.girard@cairn.test');
        $forClaire = $this->statement('claire.martin@maison-dupont.test');

        self::assertCount(5, $forThomas->lines);
        self::assertSame('Audit of the performance', $forThomas->lines[0]->getLabel(), 'the latest line first');
        self::assertEquals($forThomas->summary, $forClaire->summary, 'the balance is the same for the client');
        self::assertSame(
            array_map(static fn (BudgetLine $line): ?int => $line->getId(), $forThomas->lines),
            array_map(static fn (BudgetLine $line): ?int => $line->getId(), $forClaire->lines),
        );
    }

    public function testALineKeepsItsIssueFromWhoDoesNotSeeIt(): void
    {
        $hiddenFrom = fn (string $email): array => array_values(array_map(
            static fn (BudgetLine $line): ?int => $line->getId(),
            array_filter($this->statement($email)->lines, fn (BudgetLine $line): bool => $this->statement($email)->hides($line)),
        ));

        self::assertSame([], $hiddenFrom('thomas.girard@cairn.test'));
        self::assertSame([$this->internal->getId()], $hiddenFrom('claire.martin@maison-dupont.test'), 'the internal task is not named to the client, its quantity still counts');
    }

    public function testTheLinesOfAnIssueAreReadByWhoReadsTheBudgets(): void
    {
        $lines = fn (string $email, string $key): array => array_map(
            static fn (BudgetLine $line): array => [$line->getBudget()->getName(), $line->getStatus(), $line->getQuantity()],
            self::getContainer()->get(QueryBus::class)->ask(new ListIssueBudgetLines($this->scene->user($email), $this->scene->issue($key))),
        );
        $expected = [['Order 2026-01', BudgetLineStatus::Approved, 420], ['Order 2026-10', BudgetLineStatus::Approved, 420]];

        self::assertSame($expected, $lines('thomas.girard@cairn.test', 'MD-SPT-8'));
        self::assertSame($expected, $lines('claire.martin@maison-dupont.test', 'MD-SPT-8'), 'the client reads the budgets too');
        self::assertSame([], $lines('thomas.girard@cairn.test', 'MD-SPT-1'), 'no line names this issue');
        self::assertSame([], $lines('claire.martin@maison-dupont.test', 'MD-SPT-6'), 'an issue she does not see');

        self::commandBus()->dispatch(new UpdateProject((int) $this->scene->shop->getId(), withoutBudgets: true));
        self::assertSame([], $lines('thomas.girard@cairn.test', 'MD-SPT-8'), 'a project without budgets shows none');
    }

    private function statement(string $email): BudgetStatement
    {
        return self::getContainer()->get(QueryBus::class)->ask(new ShowBudget($this->scene->user($email), $this->second));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Budget;

use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Message\Command\Budget\UpdateBudget;
use App\Message\Command\Budget\UpdateBudgetLine;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The totals of the budgets of the shop: an order of ten days (a day and
 * a half approved, two days waiting), one of five (a day approved) and a
 * closed one of eight, three of them consumed. Thomas manages the
 * budgets, Claire, the client, reads them, Marc has no role on the shop.
 */
final class TotalsTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private BudgetLine $pending;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $first = $this->scene->budget('Order 2026-01', 10);
        $this->scene->line($first, BudgetLineStatus::Approved, 1.5, 'MD-SPT-8');
        $this->pending = $this->scene->line($first, BudgetLineStatus::Pending, 2, 'MD-SPT-7');
        $this->scene->line($this->scene->budget('Order 2026-10', 5), BudgetLineStatus::Approved, 1, label: 'Server upgrade');
        $closed = $this->scene->budget('Order 2025-06', 8);
        $this->scene->line($closed, BudgetLineStatus::Approved, 3, label: 'Redesign of the home page');
        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateBudget($this->thomas(), (int) $closed->getId(), closed: true));
    }

    public function testTheTotalsCountTheOpenBudgetsOnly(): void
    {
        $totals = $this->totals('claire.martin@maison-dupont.test')->render()->crawler()->text();

        self::assertStringContainsString('Open budgets 15 d 2 budgets', $totals, 'the closed order is history');
        self::assertStringContainsString('Consumed 2.5 d', $totals);
        self::assertStringContainsString('Left 12.5 d', $totals);
        self::assertStringContainsString('To approve 2 d', $totals);
    }

    public function testTheTotalsFollowALineMarkedOnACard(): void
    {
        $totals = $this->totals('thomas.girard@cairn.test');
        self::assertStringContainsString('Consumed 2.5 d', $totals->render()->crawler()->text());

        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateBudgetLine($this->thomas(), (int) $this->pending->getId(), BudgetLineStatus::Approved));
        $totals->emit('budget:changed');
        $text = $totals->render()->crawler()->text();

        self::assertStringContainsString('Consumed 4.5 d', $text);
        self::assertStringContainsString('Left 10.5 d', $text);
        self::assertStringContainsString('To approve 0 d', $text);
    }

    public function testTheTotalsAreDrawnOnlyForWhoReadsTheBudgets(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->totals('marc.petit@cairn.test')->render();
    }

    private function totals(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Budget:Totals', ['project' => $this->scene->shop])->actingAs($this->scene->user($email));
    }

    private function thomas(): int
    {
        return (int) $this->scene->user('thomas.girard@cairn.test')->getId();
    }
}

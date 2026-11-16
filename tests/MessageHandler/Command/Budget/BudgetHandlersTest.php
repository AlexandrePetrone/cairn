<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Budget;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\JournalEntry;
use App\Message\Command\Budget\AddBudgetLine;
use App\Message\Command\Budget\CreateBudget;
use App\Message\Command\Budget\DeleteBudget;
use App\Message\Command\Budget\RemoveBudgetLine;
use App\Message\Command\Budget\UpdateBudget;
use App\Message\Command\Budget\UpdateBudgetLine;
use App\Message\Command\Project\UpdateProject;
use App\Message\CommandBus;
use App\MessageHandler\Command\Budget\BudgetException;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The budgets of the shop and their lines. Thomas manages Maison Dupont
 * (`budget.manage`), Nadia develops, Claire is the client: both only read
 * the budgets. MD-SPT-8 is a feature of the shop, MD-SPT-6 an internal
 * task.
 */
final class BudgetHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private ShopScene $scene;
    private int $thomas;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->thomas = (int) $this->scene->user('thomas.girard@cairn.test')->getId();
    }

    public function testAProjectHasBudgetsOnceItHasAUnit(): void
    {
        try {
            $this->commands->dispatch(new CreateBudget($this->thomas, (int) $this->scene->shop->getId(), 'Order 2026-01', 4200));
            self::fail('The shop has no unit yet.');
        } catch (BudgetException) {
        }

        $budget = $this->scene->budget('  Order 2026-01 ', 10);

        self::assertSame('Order 2026-01', $budget->getName());
        self::assertSame(4200, $budget->getVolume());
        self::assertTrue($budget->isOpen());
        self::assertSame(BudgetUnit::Days, $this->scene->shop->getBudgetUnit());
    }

    public function testTheUnitChangesAtAnyTimeAndMayBeTakenAway(): void
    {
        $budget = $this->scene->budget();

        $this->commands->dispatch(new UpdateProject((int) $this->scene->shop->getId(), actorId: $this->thomas, budgetUnit: BudgetUnit::Hours));
        self::assertSame(BudgetUnit::Hours, $this->scene->shop->getBudgetUnit());
        self::assertSame(4200, $budget->getVolume(), 'the volume is in minutes: nothing is converted');

        $this->commands->dispatch(new UpdateProject((int) $this->scene->shop->getId(), actorId: $this->thomas, withoutBudgets: true));
        self::assertFalse($this->scene->shop->hasBudgets(), 'the project shows no budget any more');
        self::assertSame(4200, $budget->getVolume(), 'those it had are kept');
    }

    public function testAProjectWithoutAUnitTakesNoBudgetActivity(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Pending, 1, 'MD-SPT-8');
        $this->commands->dispatch(new UpdateProject((int) $this->scene->shop->getId(), actorId: $this->thomas, withoutBudgets: true));

        foreach ([
            new UpdateBudget($this->thomas, (int) $budget->getId(), closed: true),
            new AddBudgetLine($this->thomas, (int) $budget->getId(), label: 'Server upgrade'),
            new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Approved),
            new RemoveBudgetLine($this->thomas, (int) $line->getId()),
        ] as $command) {
            try {
                $this->commands->dispatch($command);
                self::fail($command::class.' is refused on a project without a unit.');
            } catch (BudgetException) {
            }
        }
        self::assertTrue($budget->isOpen());
        self::assertSame(BudgetLineStatus::Pending, $line->getStatus());
        self::assertNotNull($line->getId(), 'the line is still there');
    }

    public function testANameIsUniqueWithinItsProject(): void
    {
        $this->scene->budget('Order 2026-01');
        $other = $this->scene->budget('Order 2026-10', 5);
        $this->commands->dispatch(new UpdateBudget($this->thomas, (int) $other->getId(), name: 'Order 2026-10'));

        try {
            $this->commands->dispatch(new UpdateBudget($this->thomas, (int) $other->getId(), name: 'Order 2026-01'));
            self::fail('The name is taken.');
        } catch (BudgetException) {
        }

        $this->expectException(BudgetException::class);
        $this->scene->budget('Order 2026-01');
    }

    public function testOnlyWhoManagesBudgetsWritesThem(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Pending, 1, 'MD-SPT-8');
        $nadia = (int) $this->scene->user('nadia.benali@cairn.test')->getId();
        $claire = (int) $this->scene->user('claire.martin@maison-dupont.test')->getId();

        foreach ([
            new CreateBudget($nadia, (int) $this->scene->shop->getId(), 'Order 2026-10', 2100),
            new UpdateBudget($claire, (int) $budget->getId(), volume: 42000),
            new AddBudgetLine($claire, (int) $budget->getId(), BudgetLineStatus::Approved, -4200, label: 'A gift to myself'),
            new UpdateBudgetLine($claire, (int) $line->getId(), BudgetLineStatus::Approved),
            new RemoveBudgetLine($nadia, (int) $line->getId()),
            new DeleteBudget($nadia, (int) $budget->getId()),
        ] as $command) {
            try {
                $this->commands->dispatch($command);
                self::fail('Neither a developer nor a client manages the budgets.');
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame(4200, $budget->getVolume());
        self::assertSame(BudgetLineStatus::Pending, $line->getStatus());
    }

    public function testAClosedBudgetKeepsItsLinesAndTakesNoNewOne(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Approved, 0.5, label: 'Server upgrade');

        $this->commands->dispatch(new UpdateBudget($this->thomas, (int) $budget->getId(), closed: true));
        self::assertFalse($budget->isOpen());
        self::assertNotNull($budget->getClosedAt());

        try {
            $this->scene->line($budget, BudgetLineStatus::Approved, 0.5, label: 'Another one');
            self::fail('A closed budget takes no new line.');
        } catch (BudgetException) {
        }
        self::assertNotNull($this->entityManager->getRepository(BudgetLine::class)->find($line->getId()));

        $this->commands->dispatch(new UpdateBudget($this->thomas, (int) $budget->getId(), closed: false));
        self::assertTrue($budget->isOpen());
    }

    public function testABudgetIsDeletedWithItsLinesOnceClosed(): void
    {
        $budget = $this->scene->budget('Order 2026-01');
        $this->scene->line($budget, BudgetLineStatus::Approved, 2, 'MD-SPT-8');
        $this->scene->line($budget, BudgetLineStatus::Refused, label: 'Rewrite of the checkout');
        $kept = $this->scene->line($this->scene->budget('Order 2026-10'), BudgetLineStatus::Pending, 1, 'MD-SPT-8');
        $journal = $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $this->scene->issue('MD-SPT-8')]);
        $count = fn (string $class): int => (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(e.id) FROM %s e', $class))->getSingleScalarResult();

        try {
            $this->commands->dispatch(new DeleteBudget($this->thomas, (int) $budget->getId()));
            self::fail('An open budget is not deleted.');
        } catch (BudgetException) {
        }
        self::assertSame([2, 3], [$count(Budget::class), $count(BudgetLine::class)]);

        $this->commands->dispatch(new UpdateBudget($this->thomas, (int) $budget->getId(), closed: true));
        $this->commands->dispatch(new DeleteBudget($this->thomas, (int) $budget->getId()));

        self::assertSame([1, 1], [$count(Budget::class), $count(BudgetLine::class)], 'the budget went with its two lines, the other one stays whole');
        self::assertSame(BudgetLineStatus::Pending, $kept->getStatus());
        self::assertSame($journal, $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $this->scene->issue('MD-SPT-8')]), 'the issue its lines named is left alone');
    }

    public function testALineNamesAnIssueCarriesALabelOrBoth(): void
    {
        $budget = $this->scene->budget();

        $onIssue = $this->scene->line($budget, BudgetLineStatus::Pending, 1.5, 'MD-SPT-8');
        self::assertSame('MD-SPT-8', $onIssue->getIssue()?->getKey());
        self::assertNull($onIssue->getLabel());
        self::assertSame(630, $onIssue->getQuantity());

        $free = $this->scene->line($budget, BudgetLineStatus::Approved, -0.5, label: '  Goodwill gesture ');
        self::assertNull($free->getIssue());
        self::assertSame('Goodwill gesture', $free->getLabel());
        self::assertSame(-210, $free->getQuantity(), 'a negative quantity gives time back');

        $both = $this->scene->line($budget, BudgetLineStatus::Pending, 1, 'MD-SPT-8', 'Second export format');
        self::assertSame('Second export format', $both->getLabel());
        self::assertNotSame($onIssue->getId(), $both->getId(), 'an issue may have several lines on a budget');

        $this->expectException(BudgetException::class);
        $this->scene->line($budget, BudgetLineStatus::Approved, 1);
    }

    public function testALineToApproveOrApprovedHasAQuantity(): void
    {
        $budget = $this->scene->budget();

        $line = $this->scene->line($budget, BudgetLineStatus::ToEstimate, 3, 'MD-SPT-8');
        self::assertNull($line->getQuantity(), 'to estimate, it has no quantity whatever is given');

        try {
            $this->scene->line($budget, BudgetLineStatus::Pending, null, 'MD-SPT-8');
            self::fail('A pending line has a quantity.');
        } catch (BudgetException) {
        }
        try {
            $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Approved));
            self::fail('Approved, it needs a quantity.');
        } catch (BudgetException) {
        }
        self::assertSame(BudgetLineStatus::ToEstimate, $line->getStatus());

        $free = $this->scene->line($budget, BudgetLineStatus::Pending, 0, 'MD-SPT-8');
        self::assertSame(0, $free->getQuantity(), 'zero is a quantity: an estimate of nothing');
        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Approved, 0));
        self::assertSame([BudgetLineStatus::Approved, 0], [$line->getStatus(), $line->getQuantity()]);

        try {
            $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), withoutQuantity: true));
            self::fail('Approved, it does not lose its quantity.');
        } catch (BudgetException) {
        }
        self::assertSame(0, $line->getQuantity());
    }

    public function testALineIsRefusedWithOrWithoutAQuantity(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::ToEstimate, null, 'MD-SPT-8');

        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Refused));
        self::assertSame([BudgetLineStatus::Refused, null], [$line->getStatus(), $line->getQuantity()], 'refused before anyone estimated it');

        $estimated = $this->scene->line($budget, BudgetLineStatus::Refused, 3, 'MD-SPT-8');
        self::assertSame(1260, $estimated->getQuantity(), 'refused, it keeps what was asked');
        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $estimated->getId(), withoutQuantity: true));
        self::assertNull($estimated->getQuantity());
        self::assertNull($this->scene->line($budget, BudgetLineStatus::Refused, null, 'MD-SPT-8')->getQuantity());
    }

    public function testTheManagerSetsTheStatusOfALineByHand(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::ToEstimate, null, 'MD-SPT-8');
        $place = $this->scene->issue('MD-SPT-8')->getPlace();
        $journal = $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $this->scene->issue('MD-SPT-8')]);

        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Pending, 840));
        self::assertSame([BudgetLineStatus::Pending, 840], [$line->getStatus(), $line->getQuantity()]);

        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Approved));
        self::assertSame([BudgetLineStatus::Approved, 840], [$line->getStatus(), $line->getQuantity()], 'a property left out keeps its value');

        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), quantity: 1680, label: 'With the second format'));
        self::assertSame([BudgetLineStatus::Approved, 1680, 'With the second format'], [$line->getStatus(), $line->getQuantity(), $line->getLabel()]);

        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::Refused, label: ''));
        self::assertSame([BudgetLineStatus::Refused, 1680, null], [$line->getStatus(), $line->getQuantity(), $line->getLabel()]);

        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), BudgetLineStatus::ToEstimate));
        self::assertNull($line->getQuantity(), 'back to estimate, it loses its quantity');

        self::assertSame($place, $this->scene->issue('MD-SPT-8')->getPlace(), 'the workflow of the issue is left alone');
        self::assertSame($journal, $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $this->scene->issue('MD-SPT-8')]), 'and nothing is written in its history');
    }

    public function testALineWithoutAnIssueKeepsALabel(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Approved, 0.5, label: 'Server upgrade');

        $this->expectException(BudgetException::class);
        $this->commands->dispatch(new UpdateBudgetLine($this->thomas, (int) $line->getId(), label: ''));
    }

    public function testALineNamesAnIssueOfItsProject(): void
    {
        $budget = $this->scene->budget();
        $elsewhere = $this->scene->intranetBug();

        $this->expectException(BudgetException::class);
        $this->commands->dispatch(new AddBudgetLine($this->thomas, (int) $budget->getId(), BudgetLineStatus::Pending, 420, $elsewhere->getId()));
    }

    public function testALineIsDeletedForGood(): void
    {
        $budget = $this->scene->budget();
        $line = $this->scene->line($budget, BudgetLineStatus::Approved, 1, 'MD-SPT-8');
        $id = (int) $line->getId();

        $this->commands->dispatch(new RemoveBudgetLine($this->thomas, $id));

        self::assertNull($this->entityManager->getRepository(BudgetLine::class)->find($id));
        self::assertNotNull($this->scene->issue('MD-SPT-8')->getId(), 'the issue it named stays');
    }
}

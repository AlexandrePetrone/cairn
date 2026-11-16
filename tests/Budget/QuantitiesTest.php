<?php

declare(strict_types=1);

namespace App\Tests\Budget;

use App\Budget\Quantities;
use App\Entity\Enum\BudgetUnit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A day lasts seven hours in the tests (CAIRN_HOURS_PER_DAY), as by default.
 */
final class QuantitiesTest extends KernelTestCase
{
    private Quantities $quantities;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->quantities = self::getContainer()->get(Quantities::class);
    }

    public function testWhatIsTypedInTheUnitBecomesMinutes(): void
    {
        self::assertSame(420, $this->quantities->toMinutes(1, BudgetUnit::Days));
        self::assertSame(210, $this->quantities->toMinutes(0.5, BudgetUnit::Days));
        self::assertSame(105, $this->quantities->toMinutes(0.25, BudgetUnit::Days));
        self::assertSame(90, $this->quantities->toMinutes(1.5, BudgetUnit::Hours));
        self::assertSame(-210, $this->quantities->toMinutes(-0.5, BudgetUnit::Days));
    }

    public function testMinutesAreReadInTheUnit(): void
    {
        self::assertSame('1 d', $this->quantities->format(420, BudgetUnit::Days));
        self::assertSame('1.5 d', $this->quantities->format(630, BudgetUnit::Days));
        self::assertSame('0.25 d', $this->quantities->format(105, BudgetUnit::Days));
        self::assertSame('0 d', $this->quantities->format(0, BudgetUnit::Days));
        self::assertSame('−0.5 d', $this->quantities->format(-210, BudgetUnit::Days));
        self::assertSame('10 h', $this->quantities->format(600, BudgetUnit::Hours));
    }

    public function testTheUnitIsOnlyWhatIsShown(): void
    {
        // The same minutes, read in the other unit: nothing is stored again.
        self::assertSame('7 h', $this->quantities->format(420, BudgetUnit::Hours));
        self::assertSame('3.5 h', $this->quantities->format(210, BudgetUnit::Hours));
        self::assertSame('0.14 d', $this->quantities->format(60, BudgetUnit::Days), 'an hour is not a round number of days');
    }

    public function testWhatAFormAcceptsTheCommandsAcceptWhateverADayLasts(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame(Quantities::MAX_MINUTES, (new Quantities(24, $translator))->toMinutes(Quantities::MAX_TYPED, BudgetUnit::Days), 'the longest day there is');
        self::assertLessThan(2 ** 31, Quantities::MAX_MINUTES, 'which fits the column');

        foreach ([0, -7, 25] as $hours) {
            try {
                new Quantities($hours, $translator);
                self::fail(\sprintf('A day does not last %d hours.', $hours));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testAFieldLeftAsShownKeepsItsQuantityToTheMinute(): void
    {
        // Thirty minutes, typed in hours, show as 0.07 d once the project counts in days: sent back untouched, they stay thirty.
        self::assertSame(0.07, $this->quantities->inUnit(30, BudgetUnit::Days));
        self::assertSame(29, $this->quantities->toMinutes(0.07, BudgetUnit::Days));
        self::assertSame(30, $this->quantities->fromInput(0.07, BudgetUnit::Days, 30));

        self::assertSame(42, $this->quantities->fromInput(0.1, BudgetUnit::Days, 30), 'a value typed anew is converted');
        self::assertSame(29, $this->quantities->fromInput(0.07, BudgetUnit::Days), 'as is a field that showed nothing');
    }
}

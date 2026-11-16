<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Enum\BudgetUnit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Budgets and their lines are stored in minutes; a project shows them in
 * days or in hours (`Project::$budgetUnit`). This is the one place that
 * converts: what someone typed in the unit of the project into minutes, and
 * minutes into what is read ("1.5 d", "3.5 h"). A day lasts the same number
 * of hours for the whole instance (`CAIRN_HOURS_PER_DAY`).
 */
final class Quantities
{
    /**
     * The most a volume or a line may amount to, in minutes: the bound of
     * the commands. It is there so that a quantity typed wrong is refused
     * with a message instead of overflowing its column, a 32-bit integer
     * (some two billion minutes). It is what MAX_TYPED amounts to at the
     * most, in days of twenty-four hours, so that what a form accepts the
     * commands accept too, whatever a day lasts.
     */
    public const int MAX_MINUTES = self::MAX_TYPED * 24 * 60;

    /**
     * The bound of a form, in the unit of the project, days or hours
     * alike.
     */
    public const int MAX_TYPED = 100_000;

    public function __construct(
        #[Autowire(env: 'float:CAIRN_HOURS_PER_DAY')]
        private readonly float $hoursPerDay,
        private readonly TranslatorInterface $translator,
    ) {
        if ($hoursPerDay <= 0 || $hoursPerDay > 24) {
            throw new \InvalidArgumentException(\sprintf('CAIRN_HOURS_PER_DAY is the number of hours a day lasts: more than 0 and at most 24, %s given.', $hoursPerDay));
        }
    }

    /** What was typed in the unit, in minutes. */
    public function toMinutes(float $value, BudgetUnit $unit): int
    {
        return (int) round($value * $this->minutesIn($unit));
    }

    /**
     * What a form sends back, in minutes. A field left as the form showed
     * it keeps the quantity it stood for (`$current`): shown to two
     * decimals, a quantity typed in another unit does not always read back
     * to itself (30 minutes show as 0.07 d, which is 29), and saving a form
     * must not rewrite what nobody touched.
     */
    public function fromInput(float $value, BudgetUnit $unit, ?int $current = null): int
    {
        if (null !== $current && round($value, 2) === $this->inUnit($current, $unit)) {
            return $current;
        }

        return $this->toMinutes($value, $unit);
    }

    /** Minutes in the unit, to at most two decimals: what a form shows. */
    public function inUnit(int $minutes, BudgetUnit $unit): float
    {
        return round($minutes / $this->minutesIn($unit), 2);
    }

    /** Minutes as read in the unit, with it: "1.5 d", "−0.5 d", "12 h". */
    public function format(int $minutes, BudgetUnit $unit): string
    {
        $value = $this->inUnit($minutes, $unit);
        $number = rtrim(rtrim(number_format(abs($value), 2, '.', ''), '0'), '.');
        $number = ($value < 0 ? '−' : '').str_replace('.', $this->translator->trans('budget.quantity.decimal'), $number);

        return $this->translator->trans('budget.quantity.'.$unit->value, ['%quantity%' => $number]);
    }

    private function minutesIn(BudgetUnit $unit): float
    {
        return BudgetUnit::Days === $unit ? 60 * $this->hoursPerDay : 60;
    }
}

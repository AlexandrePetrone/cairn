<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The unit a project shows its budgets in. Quantities are stored in
 * minutes whatever the unit: it only changes what is typed and read, so a
 * project may switch at any time.
 */
enum BudgetUnit: string
{
    case Days = 'days';
    case Hours = 'hours';
}

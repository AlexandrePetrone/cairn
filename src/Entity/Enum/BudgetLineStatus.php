<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a line of a budget stands, set by whoever manages the
 * budgets or by an automation rule of the project: to estimate (its
 * quantity, when it has one, is a draft that external users do not
 * read), pending the client's approval, approved (the only one a budget
 * consumes), or refused.
 */
enum BudgetLineStatus: string
{
    case ToEstimate = 'to_estimate';
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';

    /**
     * Whether a line in this status must say how much: what is asked for
     * an approval, or approved, is a quantity. A line may be refused
     * before anyone estimated it.
     */
    public function needsQuantity(): bool
    {
        return self::Pending === $this || self::Approved === $this;
    }
}

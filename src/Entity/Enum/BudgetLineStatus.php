<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a line of a budget stands, set by hand by whoever manages the
 * budgets: to estimate (it has no quantity yet), pending the client's
 * approval, approved (the only one a budget consumes), or refused.
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

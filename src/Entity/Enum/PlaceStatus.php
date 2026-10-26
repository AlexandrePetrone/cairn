<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Family of a workflow place. Shared by every workflow so that cross-category
 * views (lists, filters, stats) can sort and colour issues consistently.
 *
 * Persisted on issues (denormalised from the place) and referenced by the
 * instance configuration.
 */
enum PlaceStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Closed = 'closed';

    /**
     * Default sort order of cross-category views.
     */
    public function order(): int
    {
        return match ($this) {
            self::Open => 1,
            self::InProgress => 2,
            self::Waiting => 3,
            self::Closed => 4,
        };
    }

    /**
     * Colour token of the design system. Status colours are fixed and
     * global; places have no colour of their own.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::InProgress => 'amber',
            self::Waiting => 'violet',
            self::Closed => 'gray',
        };
    }

    public function transKey(): string
    {
        return 'status.'.$this->value;
    }
}

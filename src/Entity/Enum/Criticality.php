<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Criticality of an issue. A first-class column, not a configurable field:
 * it drives the default sort and card colours.
 */
enum Criticality: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Blocking = 'blocking';

    public const self DEFAULT = self::Normal;

    /**
     * Higher weight sorts first.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 10,
            self::Normal => 20,
            self::High => 30,
            self::Blocking => 40,
        };
    }

    /**
     * Whether the issue is highlighted as critical on dashboards.
     */
    public function isCritical(): bool
    {
        return self::High === $this || self::Blocking === $this;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Low => '▽',
            self::Normal => '●',
            self::High, self::Blocking => '▲',
        };
    }

    public function transKey(): string
    {
        return 'criticality.'.$this->value;
    }
}

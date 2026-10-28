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
}

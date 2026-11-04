<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a release stands. Only an open release takes issues; a delivered
 * or abandoned one keeps those it held.
 */
enum ReleaseStatus: string
{
    case Open = 'open';
    case Delivered = 'delivered';
    case Abandoned = 'abandoned';
}

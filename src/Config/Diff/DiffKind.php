<?php

declare(strict_types=1);

namespace App\Config\Diff;

enum DiffKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';

    public function symbol(): string
    {
        return match ($this) {
            self::Added => '+',
            self::Removed => '-',
            self::Changed => '~',
        };
    }
}

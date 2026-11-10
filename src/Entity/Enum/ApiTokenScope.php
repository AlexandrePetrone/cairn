<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What an API token lets its bearer do, within what its user may do:
 * reading only, or reading and writing.
 */
enum ApiTokenScope: string
{
    case Read = 'read';
    case Write = 'write';

    public function allows(self $scope): bool
    {
        return self::Write === $this || $scope === $this;
    }
}

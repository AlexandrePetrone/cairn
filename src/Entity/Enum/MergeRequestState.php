<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a merge request (a pull request on GitHub) stands, as the forge
 * last told us.
 */
enum MergeRequestState: string
{
    case Opened = 'opened';
    case Merged = 'merged';
    case Closed = 'closed';
}

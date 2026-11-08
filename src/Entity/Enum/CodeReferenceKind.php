<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a code reference points to on the forge.
 */
enum CodeReferenceKind: string
{
    case Commit = 'commit';
    case MergeRequest = 'merge_request';
}

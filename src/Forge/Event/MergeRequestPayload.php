<?php

declare(strict_types=1);

namespace App\Forge\Event;

use App\Entity\Enum\MergeRequestState;

/**
 * A merge request (a pull request on GitHub), as every forge describes it.
 */
final readonly class MergeRequestPayload
{
    public function __construct(
        public int $number,
        public string $title,
        public string $description,
        public string $url,
        public string $sourceBranch,
        public MergeRequestState $state,
        public string $authorName,
    ) {
    }
}

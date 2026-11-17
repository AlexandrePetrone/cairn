<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use App\Api\Resource\IssueResource;

/**
 * A page of a list of issues.
 */
final readonly class IssueList
{
    /**
     * @param list<IssueResource> $issues
     */
    public function __construct(
        /** Over every page. */
        public int $total,
        public int $page,
        public int $pages,
        public array $issues,
    ) {
    }
}

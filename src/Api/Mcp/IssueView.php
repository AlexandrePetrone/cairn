<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use App\Api\Resource\CommentResource;
use App\Api\Resource\IssueResource;
use App\Api\Resource\LinkResource;

/**
 * An issue as its page shows it to the user of the token, in one answer:
 * the resources of the REST API, put together.
 */
final readonly class IssueView
{
    /**
     * @param list<CommentResource> $comments oldest first, each with its files
     * @param list<LinkResource>    $links    the issues linked to this one
     */
    public function __construct(
        public IssueResource $issue,
        public array $comments,
        public array $links,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use App\Api\Resource\MemberResource;
use App\Api\Resource\ProjectResource;
use App\Api\Resource\ReleaseResource;
use App\Api\Resource\TrackerResource;

/**
 * A project and what its issues are made of, in one answer: the
 * resources of the REST API, put together.
 */
final readonly class ProjectView
{
    /**
     * @param list<TrackerResource> $trackers its categories, with the steps and the fields of their workflow
     * @param list<MemberResource>  $members  who an issue may be assigned to
     * @param list<ReleaseResource> $releases
     */
    public function __construct(
        public ProjectResource $project,
        public array $trackers,
        public array $members,
        public array $releases,
    ) {
    }
}

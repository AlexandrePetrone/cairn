<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Query;
use App\Repository\Pagination;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every issue list is this query: a project, a workspace, my issues, the
 * ones I created. The controller builds it whole: the viewer and the scope
 * from where it stands, the filters from the request.
 *
 * @implements Query<Pagination<Issue>>
 */
final readonly class SearchIssues implements Query
{
    /**
     * @param list<PlaceStatus> $status
     * @param list<int>         $tracker
     * @param list<int>         $assignee    user ids, 0 for unassigned
     * @param list<Criticality> $criticality
     * @param ?string           $q           words of the title, a full key, or the start of a number
     * @param bool              $closed      list closed issues too (implied when `status` names closed)
     */
    public function __construct(
        public User $viewer,
        public ?int $projectId = null,
        public ?int $workspaceId = null,
        public ?int $assigneeId = null,
        public ?int $authorId = null,
        public array $status = [],
        public array $tracker = [],
        public array $assignee = [],
        public array $criticality = [],
        public ?string $q = null,
        public bool $closed = false,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}

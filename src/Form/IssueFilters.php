<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The filters of an issue list, mapped from the query string of a GET
 * form. Which of them a list offers is decided by its route.
 */
final readonly class IssueFilters
{
    /**
     * @param list<PlaceStatus> $status
     * @param list<int>         $project
     * @param list<int>         $tracker
     * @param list<int>         $assignee    user ids, 0 for unassigned
     * @param list<Criticality> $criticality
     * @param bool              $closed      list closed issues too
     */
    public function __construct(
        public array $status = [],
        public array $project = [],
        public array $tracker = [],
        public array $assignee = [],
        public array $criticality = [],
        public string $q = '',
        public bool $closed = false,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->status && [] === $this->project && [] === $this->tracker && [] === $this->assignee && [] === $this->criticality && '' === trim($this->q) && !$this->closed;
    }
}

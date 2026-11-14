<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Message\Query\Project\ListProjects;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The filters of the administration's project list, mapped from the query
 * string.
 */
final readonly class ProjectFilters
{
    /**
     * @param list<string> $state active and/or archived
     */
    public function __construct(
        public string $q = '',
        #[Assert\All([new Assert\Choice(choices: [ListProjects::ACTIVE, ListProjects::ARCHIVED])])]
        public array $state = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->q) && [] === $this->state;
    }
}

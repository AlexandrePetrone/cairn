<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;
use App\Message\Query\Query;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every project, for the administration, sorted by workspace then name,
 * archived ones last. `state` keeps the active or the archived projects
 * (archived themselves or through their workspace), both when empty.
 *
 * @implements Query<list<Project>>
 */
final readonly class ListProjects implements Query
{
    public const string ACTIVE = 'active';
    public const string ARCHIVED = 'archived';

    /**
     * @param list<string> $state
     */
    public function __construct(
        public ?int $workspaceId = null,
        public string $q = '',
        #[Assert\All([new Assert\Choice(choices: [self::ACTIVE, self::ARCHIVED])])]
        public array $state = [],
    ) {
    }
}

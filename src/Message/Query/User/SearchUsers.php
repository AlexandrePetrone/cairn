<?php

declare(strict_types=1);

namespace App\Message\Query\User;

use App\Entity\User;
use App\Message\Query\Query;
use App\Repository\Pagination;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The accounts, for the administration: active ones first, internal
 * before external, then by name. `q` searches names and email; `type` and
 * `state` keep the listed values, all when empty.
 *
 * @implements Query<Pagination<User>>
 */
final readonly class SearchUsers implements Query
{
    public const string INTERNAL = 'internal';
    public const string EXTERNAL = 'external';
    public const string ACTIVE = 'active';
    public const string DEACTIVATED = 'deactivated';

    /**
     * @param list<string> $type
     * @param list<string> $state
     */
    public function __construct(
        public string $q = '',
        #[Assert\All([new Assert\Choice(choices: [self::INTERNAL, self::EXTERNAL])])]
        public array $type = [],
        #[Assert\All([new Assert\Choice(choices: [self::ACTIVE, self::DEACTIVATED])])]
        public array $state = [],
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}

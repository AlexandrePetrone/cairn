<?php

declare(strict_types=1);

namespace App\Form;

use App\Message\Query\User\SearchUsers;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The filters of the administration's user list, mapped from the query
 * string.
 */
final readonly class UserFilters
{
    /**
     * @param list<string> $type  internal and/or external
     * @param list<string> $state active and/or deactivated
     */
    public function __construct(
        public string $q = '',
        #[Assert\All([new Assert\Choice(choices: [SearchUsers::INTERNAL, SearchUsers::EXTERNAL])])]
        public array $type = [],
        #[Assert\All([new Assert\Choice(choices: [SearchUsers::ACTIVE, SearchUsers::DEACTIVATED])])]
        public array $state = [],
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->q) && [] === $this->type && [] === $this->state;
    }
}

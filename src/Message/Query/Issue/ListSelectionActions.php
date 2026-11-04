<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\User;
use App\Message\Query\Query;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the viewer may do at once to a selection of issues: the issues
 * they still see among those ids, and the actions valid for every one of
 * them. Only what applies to the whole selection is offered.
 *
 * @implements Query<SelectionActions>
 */
final readonly class ListSelectionActions implements Query
{
    /** The most issues one selection holds: a page of a list is 50. */
    public const int MAX = 100;

    /**
     * @param list<int> $issueIds
     */
    public function __construct(
        public User $viewer,
        #[Assert\Count(max: self::MAX)]
        #[Assert\All([new Assert\Positive()])]
        public array $issueIds,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The actor arranged an issue list (filters, sort, grouping, view): the
 * list keeps it for their next visit. `list` is the address of the list
 * without its query string, `query` the query string that arranges it; an
 * empty one forgets the view. Whether the actor may open that list is the
 * caller's to know: the list itself asks, at its own address. Handled by
 * RememberListViewHandler.
 */
final readonly class RememberListView
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $list,
        #[Assert\Length(max: 2000)]
        public string $query,
    ) {
    }
}

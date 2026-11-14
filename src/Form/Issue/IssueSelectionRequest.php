<?php

declare(strict_types=1);

namespace App\Form\Issue;

use App\Message\Query\Issue\ListSelectionActions;

/**
 * What a list sends to the selection page, mapped from the query string:
 * the ids of the checked issues and the address of the list to come back
 * to. Both selection pages, the application's and a project's, read it.
 */
final readonly class IssueSelectionRequest
{
    /**
     * @param array<mixed> $ids
     */
    public function __construct(
        public array $ids = [],
        public ?string $from = null,
    ) {
    }

    /**
     * The ids of the selection, at most ListSelectionActions::MAX: a longer
     * address keeps the first ones rather than failing.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return \array_slice(QueryIds::of($this->ids), 0, ListSelectionActions::MAX);
    }

    /**
     * Where to come back: only a path of this site, never another host
     * (`//`, `/\`).
     */
    public function from(): ?string
    {
        return null !== $this->from && 1 === preg_match('#^/(?![/\\\\])#', $this->from) ? $this->from : null;
    }
}

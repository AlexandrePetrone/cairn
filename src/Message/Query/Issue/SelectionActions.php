<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Issue;
use App\Entity\User;

/**
 * The answer to ListSelectionActions: the selected issues the viewer
 * sees, and what they may do to all of them at once. A right counts only
 * when it holds on every project of the selection.
 */
final readonly class SelectionActions
{
    /**
     * @param list<Issue>               $issues
     * @param list<SelectionTransition> $transitions common to every issue
     * @param list<User>                $assignees   members of every project of the selection
     */
    public function __construct(
        public array $issues,
        public array $transitions = [],
        public bool $canAssign = false,
        public array $assignees = [],
        public bool $canComment = false,
        public bool $canCommentPrivately = false,
        public bool $canSetCriticality = false,
    ) {
    }

    /**
     * The transition an action of the selection page names
     * (`transition:<id>`), when the selection offers it.
     */
    public function transitionFor(?string $action): ?SelectionTransition
    {
        if (null === $action || !str_starts_with($action, 'transition:')) {
            return null;
        }
        $id = substr($action, \strlen('transition:'));

        return array_find($this->transitions, static fn (SelectionTransition $transition): bool => $transition->id === $id);
    }

    /**
     * Whether the action, as the selection page names it, is offered:
     * `transition:<id>`, `assign`, `comment` or `criticality`.
     */
    public function offers(string $action): bool
    {
        if (str_starts_with($action, 'transition:')) {
            return null !== $this->transitionFor($action);
        }

        return match ($action) {
            'assign' => $this->canAssign,
            'comment' => $this->canComment,
            'criticality' => $this->canSetCriticality,
            default => false,
        };
    }

    public function hasAnything(): bool
    {
        return [] !== $this->transitions || $this->canAssign || $this->canComment || $this->canSetCriticality;
    }

    /**
     * Whether the selection spans several projects, which the page then
     * names on each line.
     */
    public function isAcrossProjects(): bool
    {
        $projects = [];
        foreach ($this->issues as $issue) {
            $projects[(int) $issue->getProject()->getId()] = true;
        }

        return \count($projects) > 1;
    }
}

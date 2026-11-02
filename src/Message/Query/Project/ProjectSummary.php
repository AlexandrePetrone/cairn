<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;

/**
 * A project and the issues of it the viewer sees, still open: all of
 * them, those waiting, those blocking.
 */
final readonly class ProjectSummary
{
    public function __construct(
        public Project $project,
        public int $open = 0,
        public int $waiting = 0,
        public int $blocking = 0,
    ) {
    }

    /**
     * The summaries grouped by workspace, in the order they come.
     *
     * @param list<self> $summaries
     *
     * @return list<non-empty-list<self>>
     */
    public static function byWorkspace(array $summaries): array
    {
        $groups = [];
        foreach ($summaries as $summary) {
            $groups[(int) $summary->project->getWorkspace()->getId()][] = $summary;
        }

        return array_values($groups);
    }
}

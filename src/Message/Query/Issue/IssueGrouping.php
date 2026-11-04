<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Issue;
use App\Workflow\PlaceCatalog;

/**
 * What an issue list can group its issues by (SearchIssues::$group). The
 * groups follow one another in the list's order; each has a key, a text
 * that both the SQL (Repository/IssueOrder::groupKey()) and keyOf() give.
 */
enum IssueGrouping: string
{
    case Place = 'place';
    case Criticality = 'criticality';
    case Assignee = 'assignee';
    case Project = 'project';
    case Release = 'release';

    /** The key of the issues nobody is assigned to, or in no release. */
    public const string NOBODY = 'none';

    public function keyOf(Issue $issue): string
    {
        return match ($this) {
            self::Place => PlaceCatalog::key($issue->getTracker()->getWorkflowId(), $issue->getPlace()),
            self::Criticality => $issue->getCriticality()->value,
            self::Assignee => null === $issue->getAssignee() ? self::NOBODY : (string) $issue->getAssignee()->getId(),
            self::Project => (string) $issue->getProject()->getId(),
            self::Release => null === $issue->getRelease() ? self::NOBODY : (string) $issue->getRelease()->getId(),
        };
    }

    /**
     * The key of a group as the SQL returns it.
     */
    public static function key(mixed $value): string
    {
        return match (true) {
            null === $value => self::NOBODY,
            $value instanceof \BackedEnum => (string) $value->value,
            \is_scalar($value) => (string) $value,
            default => throw new \LogicException('A group key is a scalar.'),
        };
    }
}

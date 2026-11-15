<?php

declare(strict_types=1);

namespace App\Message\Query\Automation;

use App\Entity\AutomationRun;
use App\Entity\Enum\RunOutcome;
use App\Entity\Project;
use App\Message\Query\Query;
use App\Repository\Pagination;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The executions of the automation rules of a project, the latest first,
 * with their issue, their rule and who triggered them: all of them, or
 * those of one rule, or those that ended a given way. For who configures
 * the project, who sees every issue of it.
 *
 * @implements Query<Pagination<AutomationRun>>
 */
final readonly class ListAutomationRuns implements Query
{
    public function __construct(
        public Project $project,
        public ?int $ruleId = null,
        public ?RunOutcome $outcome = null,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}

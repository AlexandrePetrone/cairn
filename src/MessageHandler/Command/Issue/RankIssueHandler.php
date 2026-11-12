<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\RankIssue;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gives the issue the rank halfway between its two neighbours in the
 * whole column, the issues the actor does not see included: under the
 * card above when there is one, over the card below otherwise, so that a
 * filtered or partly loaded column still gives one answer. When no rank
 * fits between the two, or the neighbour shares its rank, the column is
 * ranked anew once, then it fits.
 * Unlike the other issue commands it records nothing: the arrangement of
 * the board is not an activity on the issue.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RankIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RankIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueRank, $issue->getProject()), 'arrange the kanban');

        $above = $this->neighbour($actor, $issue, $command->aboveKey);
        $below = $this->neighbour($actor, $issue, $command->belowKey);
        if (null === $above && null === $below) {
            return;
        }

        $rank = $this->between($issue, $above, $below);
        if (null === $rank) {
            $this->issues->spreadRanks($issue->getTracker(), $issue->getPlace());
            foreach (array_filter([$issue, $above, $below]) as $ranked) {
                $this->entityManager->refresh($ranked);
            }
            $rank = $this->between($issue, $above, $below) ?? throw new \LogicException('A column ranked anew has room between any two issues.');
        }

        $issue->rankAt($rank);
    }

    /**
     * The rank halfway between the neighbour and what follows it in the
     * column, null when the two are too close or when the neighbour shares
     * its rank with another issue.
     */
    private function between(Issue $issue, ?Issue $above, ?Issue $below): ?int
    {
        $neighbour = $above ?? $below;
        if (null === $neighbour || $this->issues->sharesRank($neighbour, $issue)) {
            return null;
        }
        if (null !== $above) {
            $high = $above->getBoardRank();
            $low = $this->issues->rankBelow($above, $issue) ?? $high - 2 * Issue::RANK_STEP;
        } else {
            $low = $neighbour->getBoardRank();
            $high = $this->issues->rankAbove($neighbour, $issue) ?? $low + 2 * Issue::RANK_STEP;
        }
        $rank = intdiv($low + $high, 2);

        return $rank > $low && $rank < $high ? $rank : null;
    }

    /**
     * A card of the same column that the actor sees.
     */
    private function neighbour(User $actor, Issue $issue, ?string $key): ?Issue
    {
        if (null === $key || '' === trim($key)) {
            return null;
        }
        $neighbour = $this->support->visibleIssue($actor, $key);
        if ($neighbour === $issue || $neighbour->getTracker() !== $issue->getTracker() || $neighbour->getPlace() !== $issue->getPlace()) {
            throw IssueException::notInColumn($issue->getKey(), $neighbour->getKey());
        }

        return $neighbour;
    }
}

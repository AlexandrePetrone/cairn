<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Entity\IssueLink;
use App\Message\Query\Issue\ListLinks;
use App\Repository\EntityList;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One query per direction, the other end named `i` so that
 * Security/Visibility restricts it as it restricts the lists: the SQL
 * side of AccessControl::canSee() on linked issues, which
 * ShowIssueVisibilityTest compares.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListLinksHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
    ) {
    }

    /**
     * @return list<IssueLink>
     */
    public function __invoke(ListLinks $query): array
    {
        $links = [];
        foreach (['source' => 'target', 'target' => 'source'] as $side => $other) {
            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->select('l', 'i', 't', 'p', 'w')
                ->from(IssueLink::class, 'l')
                ->join("l.$other", 'i')
                ->join('i.tracker', 't')
                ->join('i.project', 'p')
                ->join('p.workspace', 'w')
                ->where("l.$side = :issue")
                ->setParameter('issue', $query->issue);
            $this->visibility->restrictIssues($queryBuilder, $query->viewer);

            $links = [...$links, ...EntityList::of($queryBuilder->getQuery(), IssueLink::class)];
        }
        usort($links, static fn (IssueLink $a, IssueLink $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

        return $links;
    }
}

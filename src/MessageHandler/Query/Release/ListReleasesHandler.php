<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Release;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Release;
use App\Message\Query\Release\ListReleases;
use App\Message\Query\Release\ReleaseSummary;
use App\Repository\ReleaseRepository;
use App\Repository\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The releases, then their issues counted by release and status under the
 * visibility of the issue lists: an external user does not count the
 * private issues they would not find in the list.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListReleasesHandler
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
    ) {
    }

    /**
     * @return list<ReleaseSummary>
     */
    public function __invoke(ListReleases $query): array
    {
        $releases = $this->releases->findByProject($query->project);
        if ([] === $releases) {
            return [];
        }

        $counts = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.release) AS releaseId', 'i.status AS status', 'COUNT(i.id) AS issues')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->where('i.project = :project')
            ->andWhere('i.release IS NOT NULL')
            ->groupBy('i.release', 'i.status')
            ->setParameter('project', $query->project);
        $this->visibility->restrictIssues($counts, $query->viewer);

        $empty = array_fill_keys(array_map(static fn (PlaceStatus $status): string => $status->value, PlaceStatus::cases()), 0);
        $byRelease = [];
        foreach ($counts->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof PlaceStatus ? $row['status']->value : (string) $row['status'];
            $byRelease[(int) $row['releaseId']][$status] = (int) $row['issues'];
        }

        return array_map(
            static fn (Release $release): ReleaseSummary => new ReleaseSummary($release, array_replace($empty, $byRelease[(int) $release->getId()] ?? [])),
            $releases,
        );
    }
}

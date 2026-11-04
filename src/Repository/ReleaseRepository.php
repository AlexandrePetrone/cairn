<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ReleaseStatus;
use App\Entity\Project;
use App\Entity\Release;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Release>
 */
class ReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Release::class);
    }

    /**
     * The releases of a project: the open ones by planned date, undated
     * last, then the delivered and abandoned ones, latest first. A project
     * holds a handful, sorted here rather than in SQL.
     *
     * @return list<Release>
     */
    public function findByProject(Project $project, bool $openOnly = false): array
    {
        $releases = $this->findBy($openOnly ? ['project' => $project, 'status' => ReleaseStatus::Open] : ['project' => $project]);
        $order = static fn (Release $release): array => $release->isOpen()
            ? [0, null === $release->getPlannedOn(), $release->getPlannedOn()?->getTimestamp(), $release->getId()]
            : [1, false, -($release->getDeliveredAt() ?? $release->getCreatedAt())->getTimestamp(), -$release->getId()];
        usort($releases, static fn (Release $a, Release $b): int => $order($a) <=> $order($b));

        return $releases;
    }

    public function hasReleases(Project $project): bool
    {
        return null !== $this->findOneBy(['project' => $project]);
    }

    public function findOneByName(Project $project, string $name): ?Release
    {
        return $this->findOneBy(['project' => $project, 'name' => trim($name)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/**
 * The SQL side of AccessControl, for the query handlers that list
 * projects, trackers or issues: restrictProjects() is canView(),
 * restrictIssues() is canSee(). Both sides must always agree, and
 * SearchIssuesVisibilityTest and ListVisibleProjectsTest compare them on
 * the development dataset. The query builder names the project `p`, the
 * tracker `t` and the issue `i`.
 */
final class Visibility
{
    public function __construct(
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * Internal users see every project. An external user sees a project
     * when they hold a membership on it or on its workspace whose role
     * still exists in the configuration in force.
     */
    public function restrictProjects(QueryBuilder $queryBuilder, User $viewer): void
    {
        if ($viewer->isInternal()) {
            return;
        }

        $membership = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Membership::class, 'm')
            ->where('m.user = :viewer')
            ->andWhere('m.role IN (:roles)')
            ->andWhere('m.project = p OR IDENTITY(m.workspace) = IDENTITY(p.workspace)');

        $queryBuilder
            ->andWhere($queryBuilder->expr()->exists($membership->getDQL()))
            ->setParameter('viewer', $viewer)
            ->setParameter('roles', $this->config->definition()->roleIds());
    }

    /**
     * An external user sees the public trackers only, of a project they
     * see: the project is not restricted here, the caller resolved it.
     */
    public function restrictTrackers(QueryBuilder $queryBuilder, User $viewer): void
    {
        if ($viewer->isInternal()) {
            return;
        }

        $queryBuilder->andWhere('t.private = false');
    }

    /**
     * An external user sees an issue of a project they see when neither
     * the issue nor its tracker is private.
     */
    public function restrictIssues(QueryBuilder $queryBuilder, User $viewer): void
    {
        if ($viewer->isInternal()) {
            return;
        }

        $queryBuilder->andWhere('i.private = false');
        $this->restrictTrackers($queryBuilder, $viewer);
        $this->restrictProjects($queryBuilder, $viewer);
    }
}

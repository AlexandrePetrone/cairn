<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/**
 * The SQL side of AccessControl::canView() and canSee(), for the query
 * handlers that list projects or issues; both sides must always agree,
 * and SearchIssuesVisibilityTest and ListVisibleProjectsTest compare them
 * on the development dataset. The query builder names the project `p`
 * and, for issues, the issue `i` and its tracker `t`.
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
     * An external user sees an issue of a project they see when neither
     * the issue nor its tracker is private.
     */
    public function restrictIssues(QueryBuilder $queryBuilder, User $viewer): void
    {
        if ($viewer->isInternal()) {
            return;
        }

        $queryBuilder
            ->andWhere('i.private = false')
            ->andWhere('t.private = false');
        $this->restrictProjects($queryBuilder, $viewer);
    }
}

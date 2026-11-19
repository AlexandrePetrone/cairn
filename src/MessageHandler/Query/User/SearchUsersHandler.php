<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\User;

use App\Entity\User;
use App\Message\Query\User\SearchUsers;
use App\Repository\EntityList;
use App\Repository\Pagination;
use App\Repository\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class SearchUsersHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Pagination<User>
     */
    public function __invoke(SearchUsers $query): Pagination
    {
        $total = (int) $this->filtered($query)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $users = EntityList::of(
            $this->filtered($query)
                ->orderBy('u.active', \SortDirection::Descending)
                ->addOrderBy('u.internal', \SortDirection::Descending)
                ->addOrderBy('u.lastName')
                ->addOrderBy('u.firstName')
                ->setFirstResult(($query->page - 1) * self::PER_PAGE)
                ->setMaxResults(self::PER_PAGE)
                ->getQuery(),
            User::class,
        );

        return new Pagination($users, $total, $query->page, self::PER_PAGE);
    }

    private function filtered(SearchUsers $query): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()->select('u')->from(User::class, 'u');

        if ('' !== trim($query->q)) {
            $queryBuilder
                ->andWhere("LOWER(u.firstName) LIKE :text ESCAPE '!' OR LOWER(u.lastName) LIKE :text ESCAPE '!' OR u.email LIKE :text ESCAPE '!' OR LOWER(u.company) LIKE :text ESCAPE '!'")
                ->setParameter('text', SearchText::containing($query->q));
        }
        if (1 === \count($query->type)) {
            $queryBuilder->andWhere('u.internal = :internal')->setParameter('internal', SearchUsers::INTERNAL === $query->type[0]);
        }
        if (1 === \count($query->state)) {
            $queryBuilder->andWhere('u.active = :active')->setParameter('active', SearchUsers::ACTIVE === $query->state[0]);
        }

        return $queryBuilder;
    }
}

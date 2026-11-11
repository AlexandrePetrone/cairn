<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Sign-in lookup: emails are stored lowercase, whatever the user types.
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($identifier))]);
    }

    /**
     * The accounts that may be given a role, by name.
     *
     * @return list<User>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['lastName' => 'ASC', 'firstName' => 'ASC']);
    }

    /**
     * The companies already typed on an account, for the suggestions of
     * the form: distinct, by name.
     *
     * @return list<string>
     */
    public function findCompanies(): array
    {
        /** @var list<array{company: string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('DISTINCT u.company AS company')
            ->andWhere('u.company IS NOT NULL')
            ->orderBy('u.company')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'company');
    }

    /**
     * Who may still administer the instance: at least one must remain.
     */
    public function countActiveAdministrators(): int
    {
        return $this->count(['admin' => true, 'active' => true]);
    }
}

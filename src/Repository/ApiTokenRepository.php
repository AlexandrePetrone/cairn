<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findOneBySecret(string $secret): ?ApiToken
    {
        return $this->findOneBy(['hash' => ApiToken::hash($secret)]);
    }

    /**
     * The tokens of a user, revoked ones included, latest first.
     *
     * @return list<ApiToken>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Notes that the token was used, without loading nor flushing anything
     * else: authentication is not a command.
     */
    public function markUsed(ApiToken $token, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(ApiToken::class, 't')
            ->set('t.lastUsedAt', ':at')
            ->where('t.id = :id')
            ->setParameter('at', $at, 'datetime_immutable')
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();
    }
}

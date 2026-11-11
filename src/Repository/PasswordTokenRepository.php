<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * What the reset-password bundle needs to store its tokens; the
 * application never queries it directly (Security/PasswordTokens does).
 *
 * @extends ServiceEntityRepository<PasswordToken>
 *
 * @implements ResetPasswordRequestRepositoryInterface<User>
 */
class PasswordTokenRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordToken::class);
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): PasswordToken
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException('A password token belongs to a user.');
        }

        return new PasswordToken($user, $expiresAt, $selector, $hashedToken);
    }
}

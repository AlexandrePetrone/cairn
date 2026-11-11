<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswordTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

/**
 * The single-use, expiring token behind a link that lets someone choose
 * their password: an invitation to a new account or a password reset,
 * the same object either way (Security/PasswordTokens issues and
 * consumes them). It is a credential, not history: it is deleted once
 * used, replaced or long expired.
 *
 * @implements ResetPasswordRequestInterface<User>
 */
#[ORM\Entity(repositoryClass: PasswordTokenRepository::class)]
#[ORM\Table(name: 'password_token')]
class PasswordToken implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    public function __construct(User $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        $this->user = $user;
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}

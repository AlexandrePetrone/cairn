<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the account form collects; the controller turns it into CreateUser
 * or UpdateUser. No password: the person chooses it from the invitation.
 */
final class UserData
{
    public const string INTERNAL = 'internal';
    public const string ADMIN = 'admin';
    public const string EXTERNAL = 'external';

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\Length(max: 120)]
    public ?string $company = null;

    #[Assert\Choice(choices: [self::INTERNAL, self::ADMIN, self::EXTERNAL])]
    public string $type = self::EXTERNAL;

    public bool $active = true;

    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->email = $user->getEmail();
        $data->firstName = $user->getFirstName();
        $data->lastName = $user->getLastName();
        $data->company = $user->getCompany();
        $data->type = match (true) {
            $user->isAdmin() => self::ADMIN,
            $user->isInternal() => self::INTERNAL,
            default => self::EXTERNAL,
        };
        $data->active = $user->isActive();

        return $data;
    }

    public function isInternal(): bool
    {
        return self::EXTERNAL !== $this->type;
    }

    public function isAdmin(): bool
    {
        return self::ADMIN === $this->type;
    }
}

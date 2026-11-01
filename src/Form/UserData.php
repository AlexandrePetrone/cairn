<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the account form collects; the controller turns it into CreateUser
 * or UpdateUser. The password is required at creation only: on an
 * existing account, left empty, it does not change.
 */
final class UserData
{
    public const string INTERNAL = 'internal';
    public const string ADMIN = 'admin';
    public const string EXTERNAL = 'external';

    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\Choice(choices: [self::INTERNAL, self::ADMIN, self::EXTERNAL])]
    public string $type = self::EXTERNAL;

    public bool $active = true;

    #[Assert\NotBlank(groups: ['creation'])]
    #[Assert\Length(min: 8)]
    public ?string $password = null;

    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->email = $user->getEmail();
        $data->firstName = $user->getFirstName();
        $data->lastName = $user->getLastName();
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

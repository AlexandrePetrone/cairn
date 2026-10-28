<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an account that can sign in with its email and password.
 * Handled by CreateUserHandler, which returns the User.
 */
final readonly class CreateUser
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $firstName,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $lastName,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        #[\SensitiveParameter]
        public string $plainPassword,
        public bool $internal = false,
        public bool $admin = false,
    ) {
    }
}

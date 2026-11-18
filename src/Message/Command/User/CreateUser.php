<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use App\Entity\Enum\AuthMethod;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an account. Without a password it waits for its invitation
 * (InviteUser), which is the rule: only the console gives one, to the
 * first administrator of an instance. Without an actor the
 * administration or the console creates it; with one, a manager creates
 * it from the Access page of their project (`project.manage_members`),
 * and such an account is always external. An import creates the accounts
 * of people who left as deactivated: they keep their history and cannot
 * sign in. An account told to sign in through the identity provider
 * (`authMethod`, the administration's choice, when the instance has one)
 * gets neither a password nor an invitation. Handled by CreateUserHandler, which returns the User.
 */
#[Assert\Expression(
    'this.actorId === null or this.projectId !== null',
    message: 'An actor creates an account from a project.',
)]
#[Assert\Expression(
    'this.plainPassword === null or this.authMethod.value === "password"',
    message: 'An account that signs in through the identity provider has no password.',
)]
final readonly class CreateUser
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 80)]
        public string $firstName,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 80)]
        public string $lastName,
        public bool $internal = false,
        public bool $admin = false,
        #[Assert\Length(max: 120)]
        public ?string $company = null,
        #[Assert\Length(min: 8)]
        #[\SensitiveParameter]
        public ?string $plainPassword = null,
        public ?int $actorId = null,
        public ?int $projectId = null,
        public bool $active = true,
        public AuthMethod $authMethod = AuthMethod::Password,
    ) {
    }
}

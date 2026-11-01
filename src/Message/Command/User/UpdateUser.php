<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes an account, PATCH style: a property left null keeps its value.
 * An administrator is always internal; a deactivated account can no longer
 * sign in but keeps its memberships; the password is set by an
 * administrator until people can reset their own. Handled by
 * UpdateUserHandler.
 */
#[Assert\Expression(
    'this.admin !== true or this.internal !== false',
    message: 'An administrator is internal.',
)]
final readonly class UpdateUser
{
    public function __construct(
        public int $userId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Email]
        public ?string $email = null,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        public ?string $firstName = null,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        public ?string $lastName = null,
        public ?bool $internal = null,
        public ?bool $admin = null,
        public ?bool $active = null,
        #[Assert\Length(min: 8)]
        #[\SensitiveParameter]
        public ?string $plainPassword = null,
    ) {
    }
}

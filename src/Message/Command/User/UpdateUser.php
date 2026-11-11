<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes an account, PATCH style: a property left null keeps its value;
 * an empty `company` clears it. An administrator is always internal; a
 * deactivated account can no longer sign in but keeps its memberships.
 * Nobody sets a password for someone else: the person chooses it from
 * an invitation or a reset link. Handled by UpdateUserHandler.
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
        #[Assert\Length(max: 180)]
        public ?string $email = null,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 80)]
        public ?string $firstName = null,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 80)]
        public ?string $lastName = null,
        #[Assert\Length(max: 120)]
        public ?string $company = null,
        public ?bool $internal = null,
        public ?bool $admin = null,
        public ?bool $active = null,
    ) {
    }
}

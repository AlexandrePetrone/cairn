<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone signed in chooses another password, and proves who they are
 * with the current one: an open session left on a screen is not enough
 * to take an account. Handled by ChangePasswordHandler.
 */
final readonly class ChangePassword
{
    public function __construct(
        public int $userId,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $currentPassword,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        #[\SensitiveParameter]
        public string $plainPassword,
    ) {
    }
}

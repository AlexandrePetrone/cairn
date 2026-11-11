<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sets the password the person chose from an invitation or a reset
 * link, and consumes the token. Handled by ResetPasswordHandler.
 */
final readonly class ResetPassword
{
    public function __construct(
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $token,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        #[\SensitiveParameter]
        public string $plainPassword,
    ) {
    }
}

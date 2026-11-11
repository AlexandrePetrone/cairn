<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Forgot password?": mails a link, valid an hour, to choose a password,
 * a first one included when the invitation was lost. Says nothing back:
 * an unknown address, a deactivated account or a request repeated too
 * soon all end quietly, so the page cannot be used to find out who has
 * an account. Handled by RequestPasswordResetHandler.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email]
        public string $email,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What "Forgot password?" asks: the address of the account.
 */
final class PasswordRequestData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Email]
    public ?string $email = null;
}

<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Security page of an account: the current password, which the form
 * checks so that the refusal shows under its field, and the new one,
 * typed twice (RepeatedType) and kept once. The controller turns it into
 * ChangePassword.
 */
final class PasswordChangeData
{
    #[Assert\NotBlank]
    #[UserPassword(message: 'account.refused.wrong_password')]
    public ?string $currentPassword = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    public ?string $newPassword = null;
}

<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The password chosen from an invitation or a reset link, typed twice by
 * the form (RepeatedType) and kept once.
 */
final class PasswordSetData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    public ?string $password = null;
}

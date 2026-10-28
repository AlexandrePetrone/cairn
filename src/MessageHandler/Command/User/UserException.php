<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

/**
 * A command on a user account cannot be carried out as asked.
 */
final class UserException extends \DomainException
{
    public static function emailTaken(string $email): self
    {
        return new self(\sprintf('An account already uses "%s".', $email));
    }
}

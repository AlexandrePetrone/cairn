<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How an account signs in: with the password it chose from its invitation
 * link, or through the identity provider of the instance
 * (Security/SingleSignOn). One or the other, never both: an account that
 * signs in through the provider holds no password.
 */
enum AuthMethod: string
{
    case Password = 'password';
    case Sso = 'sso';
}

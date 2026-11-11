<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\ResetPassword;
use App\Security\PasswordLinkException;
use App\Security\PasswordTokens;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The token names the account and is consumed. A new password ends the
 * open sessions of the account (User::isEqualTo) and its remember-me
 * cookies (signed on the password).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ResetPasswordHandler
{
    public function __construct(
        private readonly PasswordTokens $tokens,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(ResetPassword $command): void
    {
        $user = $this->tokens->consume($command->token);
        if (!$user->isActive()) {
            throw PasswordLinkException::invalid();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\RequestPasswordReset;
use App\Notification\AccountMailer;
use App\Repository\UserRepository;
use App\Security\PasswordTokens;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;

/**
 * Mails the reset link, or quietly does nothing: the caller shows the
 * same page whatever happened, and the log says why nothing was sent.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RequestPasswordResetHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordTokens $tokens,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestPasswordReset $command): void
    {
        $user = $this->users->loadUserByIdentifier($command->email);
        if (null === $user) {
            $this->logger->info('Password reset requested for an unknown address.');

            return;
        }
        if (!$user->isActive()) {
            $this->logger->info('Password reset requested for a deactivated account.', ['user' => $user->getId()]);

            return;
        }

        try {
            $token = $this->tokens->issueReset($user);
        } catch (TooManyPasswordRequestsException) {
            $this->logger->info('Password reset requested again too soon.', ['user' => $user->getId()]);

            return;
        }

        $this->mailer->sendPasswordReset($user, $token);
    }
}

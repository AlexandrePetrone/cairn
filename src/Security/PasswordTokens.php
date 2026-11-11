<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\PasswordTokenRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use SymfonyCasts\Bundle\ResetPassword\Exception\ExpiredResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;

/**
 * The links that let someone choose their password, as the handlers see
 * them: an invitation (a week, replaces any earlier link of the account)
 * or a reset (an hour, refused while a recent one is pending). The token
 * travels in the mail and comes back from the page; it is consumed once.
 * Behind it, the reset-password bundle stores a PasswordToken whose
 * verifier is hashed, and deletes it when consumed, replaced or long
 * expired. The bundle's helper is injected as its class, the interface
 * not having the per-call lifetime yet.
 */
final class PasswordTokens
{
    public const int INVITATION_LIFETIME = 7 * 86400;

    public function __construct(
        #[Autowire(service: 'symfonycasts.reset_password.helper')]
        private readonly ResetPasswordHelper $helper,
        private readonly PasswordTokenRepository $tokens,
    ) {
    }

    /**
     * The account must already have an id: the token is bound to it.
     */
    public function issueInvitation(User $user): string
    {
        $this->tokens->removeRequests($user);

        return $this->helper->generateResetToken($user, self::INVITATION_LIFETIME)->getToken();
    }

    /**
     * @throws TooManyPasswordRequestsException while a recent reset is pending
     */
    public function issueReset(User $user): string
    {
        return $this->helper->generateResetToken($user)->getToken();
    }

    /**
     * Whose token this is, left in place: for the page that shows the form.
     *
     * @throws PasswordLinkException
     */
    public function peek(string $token): User
    {
        try {
            $user = $this->helper->validateTokenAndFetchUser($token);
        } catch (ExpiredResetPasswordTokenException $e) {
            throw PasswordLinkException::expired($e);
        } catch (ResetPasswordExceptionInterface $e) {
            throw PasswordLinkException::invalid($e);
        }
        if (!$user instanceof User) {
            throw PasswordLinkException::invalid();
        }

        return $user;
    }

    /**
     * Whose token this is, deleted with every other token of the account.
     *
     * @throws PasswordLinkException
     */
    public function consume(string $token): User
    {
        $user = $this->peek($token);
        $this->helper->removeResetRequest($token);

        return $user;
    }
}

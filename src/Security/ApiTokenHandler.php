<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authenticates a request of the `api` firewall from its bearer token: a
 * token of ours, neither revoked nor expired, whose user may still use the
 * API (AccessControl::canUseApi(); the firewall's UserChecker refuses a
 * deactivated account). Every refusal reads the same from outside. The
 * token found is left on the request, where tokenOf() reads it.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    /** The request attribute holding the ApiToken that authenticated it. */
    private const string ATTRIBUTE = '_api_token';

    /** How stale the last use of a token may get before it is written again. */
    private const string USE_PRECISION = '-1 hour';

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly AccessControl $access,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The token that authenticated the request, null outside the API.
     */
    public static function tokenOf(?Request $request): ?ApiToken
    {
        $token = $request?->attributes->get(self::ATTRIBUTE);

        return $token instanceof ApiToken ? $token : null;
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // Nothing that is not shaped like a token of ours reaches the database.
        if (1 !== preg_match(ApiToken::PATTERN, $accessToken)) {
            throw new BadCredentialsException('Invalid credentials.');
        }
        $now = $this->clock->now();
        $token = $this->tokens->findOneBySecret($accessToken);
        if (null === $token || !$token->isUsable($now) || !$this->access->canUseApi($token->getUser())) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        if (null === $token->getLastUsedAt() || $token->getLastUsedAt() < $now->modify(self::USE_PRECISION)) {
            $this->tokens->markUsed($token, $now);
        }
        $this->requests->getMainRequest()?->attributes->set(self::ATTRIBUTE, $token);
        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}

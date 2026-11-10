<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\ApiTokenScope;
use App\Security\ApiTokenHandler;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `api.read` and `api.write`: whether the token that authenticated the
 * request has the scope. The `access_control` rules of `/api` ask it for
 * every request, by its method, so no operation has to. A token narrows
 * what its user may do, it never widens it: the rights themselves stay
 * AccessControl's.
 *
 * @extends Voter<string, mixed>
 */
final class ApiScopeVoter extends Voter
{
    private const string PREFIX = 'api.';

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::PREFIX) && null !== ApiTokenScope::tryFrom(substr($attribute, \strlen(self::PREFIX)));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return true === ApiTokenHandler::tokenOf($this->requests->getMainRequest())?->getScope()->allows(ApiTokenScope::from(substr($attribute, \strlen(self::PREFIX))));
    }
}

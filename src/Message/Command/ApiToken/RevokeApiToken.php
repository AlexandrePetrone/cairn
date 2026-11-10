<?php

declare(strict_types=1);

namespace App\Message\Command\ApiToken;

/**
 * Revokes one of the actor's API tokens: it stops opening the API at once
 * and stays listed. Revoking a revoked token changes nothing. Handled by
 * RevokeApiTokenHandler.
 */
final readonly class RevokeApiToken
{
    public function __construct(
        public int $actorId,
        public int $tokenId,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\ApiToken;

use App\Message\Command\ApiToken\RevokeApiToken;
use App\Repository\ApiTokenRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A token of someone else is reported as not found: nobody learns which
 * ids exist.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RevokeApiTokenHandler
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
    ) {
    }

    public function __invoke(RevokeApiToken $command): void
    {
        $token = $this->tokens->find($command->tokenId);
        if (null === $token || $token->getUser()->getId() !== $command->actorId) {
            throw ApiTokenException::notFound('API token', $command->tokenId);
        }

        $token->revoke();
    }
}

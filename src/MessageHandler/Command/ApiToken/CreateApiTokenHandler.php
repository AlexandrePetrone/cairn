<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\ApiToken;

use App\Entity\ApiToken;
use App\Message\Command\ApiToken\CreateApiToken;
use App\Message\Command\ApiToken\NewApiToken;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateApiTokenHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateApiToken $command): NewApiToken
    {
        $actor = $this->users->find($command->actorId) ?? throw ApiTokenException::notFound('User', $command->actorId);
        if (!$this->access->canUseApi($actor)) {
            throw new AccessDeniedException('The API is for internal users.');
        }
        if (null !== $command->expiresAt && $command->expiresAt <= $this->clock->now()) {
            throw ApiTokenException::alreadyExpired();
        }

        $secret = ApiToken::newSecret();
        $token = new ApiToken($actor, $command->name, $secret, $command->scope, $command->expiresAt);
        $this->entityManager->persist($token);

        return new NewApiToken($token, $secret);
    }
}

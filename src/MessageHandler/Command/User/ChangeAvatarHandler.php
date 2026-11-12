<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Attachment\AvatarStorage;
use App\Message\Command\User\ChangeAvatar;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The picture replaced, or taken away, is deleted: an account keeps one
 * file at most.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ChangeAvatarHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AvatarStorage $storage,
    ) {
    }

    public function __invoke(ChangeAvatar $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);

        $previous = $user->getAvatar();
        $user->changeAvatar(null === $command->file ? null : $this->storage->store($command->file));
        if (null !== $previous) {
            $this->storage->delete($previous);
        }
    }
}

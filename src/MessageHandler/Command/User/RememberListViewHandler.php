<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Entity\ListView;
use App\Message\Command\User\RememberListView;
use App\Repository\ListViewRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A view is personal, like following an issue: no journal entry, no
 * event. The list as it comes is not a view to keep: the row goes.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RememberListViewHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ListViewRepository $views,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RememberListView $command): void
    {
        $actor = $this->users->find($command->actorId) ?? throw UserException::notFound($command->actorId);
        $view = $this->views->findOne($actor, $command->list);

        if ('' === $command->query) {
            if (null !== $view) {
                $this->entityManager->remove($view);
            }

            return;
        }

        if (null === $view) {
            $this->entityManager->persist(new ListView($actor, $command->list, $command->query));

            return;
        }

        $view->arrange($command->query);
    }
}

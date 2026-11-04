<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Release;

use App\Entity\Enum\ReleaseStatus;
use App\Message\Command\Release\UpdateRelease;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ReleaseRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateReleaseHandler
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateRelease $command): void
    {
        $release = $this->releases->find($command->releaseId) ?? throw ReleaseException::notFound('Release', $command->releaseId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ReleaseManage, $release->getProject());
        if (null !== $command->name) {
            $other = $this->releases->findOneByName($release->getProject(), $command->name);
            if (null !== $other && $other !== $release) {
                throw ReleaseException::nameTaken(trim($command->name));
            }
        }

        if (null !== $command->name) {
            $release->rename($command->name);
        }
        if ($command->unplanned) {
            $release->plan(null);
        } elseif (null !== $command->plannedOn) {
            $release->plan($command->plannedOn);
        }
        if (null !== $command->description) {
            $release->describe($command->description);
        }
        if (null !== $command->status && $release->getStatus() !== $command->status) {
            match ($command->status) {
                ReleaseStatus::Open => $release->reopen(),
                ReleaseStatus::Delivered => $release->deliver(),
                ReleaseStatus::Abandoned => $release->abandon(),
            };
        }
    }
}

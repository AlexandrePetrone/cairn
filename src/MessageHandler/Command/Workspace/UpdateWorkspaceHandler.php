<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Workspace;

use App\Message\Command\Workspace\UpdateWorkspace;
use App\Repository\WorkspaceRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateWorkspaceHandler
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
    ) {
    }

    public function __invoke(UpdateWorkspace $command): void
    {
        $workspace = $this->workspaces->find($command->workspaceId) ?? throw WorkspaceException::notFound($command->workspaceId);

        if (null !== $command->name) {
            $workspace->rename($command->name);
        }
        if (null !== $command->archived) {
            $command->archived ? $workspace->archive() : $workspace->unarchive();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Release;

use App\Entity\Release;
use App\Message\Command\Release\CreateRelease;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\ReleaseRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateReleaseHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ReleaseRepository $releases,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateRelease $command): Release
    {
        $project = $this->projects->find($command->projectId) ?? throw ReleaseException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ReleaseManage, $project);
        if (null !== $this->releases->findOneByName($project, $command->name)) {
            throw ReleaseException::nameTaken(trim($command->name));
        }

        $release = new Release($project, $command->name);
        $release->plan($command->plannedOn);
        $release->describe($command->description);
        $this->entityManager->persist($release);

        return $release;
    }
}

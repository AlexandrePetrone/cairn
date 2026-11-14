<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Entity\Release;
use App\Message\Command\Import\ImportRelease;
use App\MessageHandler\Command\Release\ReleaseException;
use App\Repository\ProjectRepository;
use App\Repository\ReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function Symfony\Component\Clock\now;

#[AsMessageHandler(bus: 'command.bus')]
final class ImportReleaseHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ReleaseRepository $releases,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ImportRelease $command): Release
    {
        $project = $this->projects->find($command->projectId) ?? throw ImportException::notFound('Project', $command->projectId);
        $name = trim($command->name);
        if (null !== $this->releases->findOneByName($project, $name)) {
            throw ReleaseException::nameTaken($name);
        }

        $createdAt = $command->createdAt ?? now();
        $release = Release::imported($project, $name, $createdAt);
        $release->describe($command->description);
        $release->plan($command->plannedOn);
        if ($command->delivered) {
            $release->deliver($command->deliveredAt ?? $createdAt);
        }
        $this->entityManager->persist($release);

        return $release;
    }
}

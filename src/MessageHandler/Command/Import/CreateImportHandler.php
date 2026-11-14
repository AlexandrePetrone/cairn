<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Entity\Import;
use App\Import\ImportsDirectory;
use App\Message\Command\Import\CreateImport;
use App\Repository\ImportRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateImportHandler
{
    public function __construct(
        private readonly ImportsDirectory $directory,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly ImportRepository $imports,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateImport $command): Import
    {
        $createdBy = $this->users->find($command->createdById) ?? throw ImportException::notFound('User', $command->createdById);
        $project = $this->projects->find($command->projectId) ?? throw ImportException::notFound('Project', $command->projectId);
        if ($project->isReadOnly()) {
            throw ImportException::readOnlyProject($project->getIssuePrefix());
        }
        if (!$this->directory->has($command->file)) {
            throw ImportException::unknownArchive($command->file);
        }
        if (null !== $this->imports->findUnfinished(project: $project)) {
            throw ImportException::busy($project->getIssuePrefix());
        }
        if (null !== $this->imports->findUnfinished(file: $command->file)) {
            throw ImportException::busy($command->file);
        }

        $import = new Import($createdBy, $command->file, $project);
        $this->entityManager->persist($import);

        return $import;
    }
}

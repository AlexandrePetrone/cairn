<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Project;

use App\Entity\Project;
use App\Message\Command\Project\CreateProject;
use App\Message\Command\Project\UpdateProject;
use App\Message\CommandBus;
use App\MessageHandler\Command\Project\ProjectException;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CreateProject and UpdateProject. The fixtures hold MD
 * (SPT, INT) and GV (PRT).
 */
final class ProjectHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAProjectKeyIsUniqueWithinItsWorkspaceOnly(): void
    {
        $project = $this->commands->dispatch(new CreateProject((int) $this->fixtures->otherWorkspace->getId(), 'Site vitrine', 'SPT'));

        self::assertInstanceOf(Project::class, $project);
        self::assertSame('GV-SPT', $project->getIssuePrefix());

        $this->expectException(ProjectException::class);
        $this->commands->dispatch(new CreateProject((int) $this->fixtures->workspace->getId(), 'Autre boutique', 'SPT'));
    }

    public function testAnArchivedWorkspaceTakesNoNewProject(): void
    {
        $this->fixtures->workspace->archive();

        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('archived');
        $this->commands->dispatch(new CreateProject((int) $this->fixtures->workspace->getId(), 'Application', 'APP'));
    }

    public function testAProjectIsRenamedArchivedAndBroughtBack(): void
    {
        $projectId = (int) $this->fixtures->shop->getId();

        $this->commands->dispatch(new UpdateProject($projectId, name: 'Boutique'));
        self::assertSame('Boutique', $this->fixtures->shop->getName());
        self::assertFalse($this->fixtures->shop->isArchived());

        $this->commands->dispatch(new UpdateProject($projectId, archived: true));
        self::assertTrue($this->fixtures->shop->isArchived());
        self::assertTrue($this->fixtures->shop->isReadOnly());
        self::assertSame('Boutique', $this->fixtures->shop->getName());

        $this->commands->dispatch(new UpdateProject($projectId, archived: false));
        self::assertFalse($this->fixtures->shop->isReadOnly());
    }
}

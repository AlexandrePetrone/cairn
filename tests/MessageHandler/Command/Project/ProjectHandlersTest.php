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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAProjectKeyIsUniqueWithinItsWorkspaceOnly(): void
    {
        $project = $this->commands->dispatch(new CreateProject((int) $this->fixtures->otherWorkspace->getId(), 'Showcase site', 'SPT'));

        self::assertInstanceOf(Project::class, $project);
        self::assertSame('GV-SPT', $project->getIssuePrefix());

        $this->expectException(ProjectException::class);
        $this->commands->dispatch(new CreateProject((int) $this->fixtures->workspace->getId(), 'Another shop', 'SPT'));
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

        $this->commands->dispatch(new UpdateProject($projectId, name: 'Shop'));
        self::assertSame('Shop', $this->fixtures->shop->getName());
        self::assertFalse($this->fixtures->shop->isArchived());

        $this->commands->dispatch(new UpdateProject($projectId, archived: true));
        self::assertTrue($this->fixtures->shop->isArchived());
        self::assertTrue($this->fixtures->shop->isReadOnly());
        self::assertSame('Shop', $this->fixtures->shop->getName());

        $this->commands->dispatch(new UpdateProject($projectId, archived: false));
        self::assertFalse($this->fixtures->shop->isReadOnly());
    }

    public function testAProjectManagerChangesTheNameAndDescriptionButDoesNotArchive(): void
    {
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($thomas, $this->fixtures->workspace, 'project_manager');
        $projectId = (int) $this->fixtures->shop->getId();

        $this->commands->dispatch(new UpdateProject($projectId, 'Shop', 'The public site.', actorId: (int) $thomas->getId()));
        self::assertSame('Shop', $this->fixtures->shop->getName());
        self::assertSame('The public site.', $this->fixtures->shop->getDescription());

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateProject($projectId, archived: true, actorId: (int) $thomas->getId()));
    }

    public function testWhoDoesNotConfigureTheProjectChangesNothing(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($nadia, $this->fixtures->workspace, 'developer');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateProject((int) $this->fixtures->shop->getId(), 'Shop', actorId: (int) $nadia->getId()));
    }
}

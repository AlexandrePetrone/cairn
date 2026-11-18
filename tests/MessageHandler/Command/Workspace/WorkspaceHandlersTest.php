<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Workspace;

use App\Entity\Workspace;
use App\Message\Command\Workspace\CreateWorkspace;
use App\Message\Command\Workspace\UpdateWorkspace;
use App\Message\CommandBus;
use App\MessageHandler\Command\Workspace\WorkspaceException;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * CreateWorkspace and UpdateWorkspace.
 * The fixtures hold the MD and GV workspaces.
 */
final class WorkspaceHandlersTest extends KernelTestCase
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

    public function testAWorkspaceIsCreatedUnderAFreeKey(): void
    {
        $workspace = $this->commands->dispatch(new CreateWorkspace('Atelier Morel', 'AM'));

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertNotNull($workspace->getId());
        self::assertSame('AM', $workspace->getKey());
        self::assertFalse($workspace->isArchived());
    }

    public function testTwoWorkspacesNeverShareAKey(): void
    {
        $this->expectException(WorkspaceException::class);
        $this->commands->dispatch(new CreateWorkspace('Mobilier Durable', 'MD'));
    }

    public function testAKeyIsUppercase(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new CreateWorkspace('Atelier Morel', 'am'));
    }

    public function testAKeyMayHoldDigits(): void
    {
        $workspace = $this->commands->dispatch(new CreateWorkspace('Trois Vallées', '3V'));

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame('3V', $workspace->getKey());
    }

    public function testAKeyOfDigitsAloneIsRefused(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new CreateWorkspace('Trois Vallées', '12'));
    }

    public function testRenamingKeepsTheKey(): void
    {
        $this->commands->dispatch(new UpdateWorkspace((int) $this->fixtures->workspace->getId(), name: '  Maison Dupont & fils '));

        self::assertSame('Maison Dupont & fils', $this->fixtures->workspace->getName());
        self::assertSame('MD', $this->fixtures->workspace->getKey());
        self::assertFalse($this->fixtures->workspace->isArchived(), 'what is not given does not change');
    }

    public function testArchivingAWorkspaceMakesItsProjectsReadOnlyUntilItIsBack(): void
    {
        $workspaceId = (int) $this->fixtures->workspace->getId();

        $this->commands->dispatch(new UpdateWorkspace($workspaceId, archived: true));
        self::assertTrue($this->fixtures->shop->isReadOnly());
        self::assertFalse($this->fixtures->shop->isArchived(), 'the project keeps its own state');
        self::assertSame('Maison Dupont', $this->fixtures->workspace->getName());
        self::assertFalse($this->fixtures->otherProject->isReadOnly());

        $this->commands->dispatch(new UpdateWorkspace($workspaceId, archived: false));
        self::assertFalse($this->fixtures->shop->isReadOnly());
    }
}

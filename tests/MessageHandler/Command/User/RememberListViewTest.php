<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\User;

use App\Message\Command\User\RememberListView;
use App\Message\CommandBus;
use App\Repository\ListViewRepository;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

final class RememberListViewTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private AccessFixtures $fixtures;
    private ListViewRepository $views;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $this->views = self::getContainer()->get(ListViewRepository::class);
    }

    public function testAListKeepsOneViewPerPersonReplacedByTheNext(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);

        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/MD/SPT/issues', 'group=place'));
        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/MD/SPT/issues', 'view=board'));
        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/issues/assigned', 'group=project'));

        self::assertSame('view=board', $this->views->findOne($nadia, '/MD/SPT/issues')?->getQuery());
        self::assertSame('group=project', $this->views->findOne($nadia, '/issues/assigned')?->getQuery());
        self::assertNull($this->views->findOne($thomas, '/MD/SPT/issues'), 'a view is personal');
        self::assertSame(2, $this->views->count([]));
    }

    public function testAnEmptyArrangementForgetsTheView(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/MD/SPT/issues', 'group=place'));

        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/MD/SPT/issues', ''));
        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/MD/SPT/issues', ''));

        self::assertSame(0, $this->views->count([]), 'the row goes, and forgetting twice is fine');
    }

    public function testAnAddressTooLongToBeOneIsRefused(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);

        $this->expectException(ValidationFailedException::class);

        $this->commands->dispatch(new RememberListView((int) $nadia->getId(), '/'.str_repeat('a', 300), 'group=place'));
    }
}

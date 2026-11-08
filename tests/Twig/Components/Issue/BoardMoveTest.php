<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Issue;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Moves on the bug kanban of the scene: MD-SPT-1 is new, 4 waits for
 * Claire's acceptance (refusing it needs a comment). Nadia develops on
 * the shop, Claire is its client.
 */
final class BoardMoveTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testADropOnAPlaceOneTransitionAwayAppliesIt(): void
    {
        $mover = $this->mover('nadia.benali@cairn.test');

        $mover->call('move', ['key' => 'MD-SPT-1', 'place' => 'qualified']);
        $render = $mover->render();

        self::assertSame('qualified', $this->reloaded('MD-SPT-1')->getPlace());
        self::assertSame(['from' => 'new', 'to' => 'qualified'], $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? null);
        self::assertNotNull($mover->getEmittedEvent($render, 'issue:changed'));
    }

    public function testAPlaceNoTransitionReachesIsRefusedAndTheColumnsRedraw(): void
    {
        $mover = $this->mover('nadia.benali@cairn.test');

        $mover->call('move', ['key' => 'MD-SPT-1', 'place' => 'done']);
        $render = $mover->render();

        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace());
        self::assertSame(['from' => 'new', 'to' => 'done'], $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? null);
        self::assertNotNull($mover->getDispatchedBrowserEvent($render, 'toast:show'));
    }

    public function testATransitionThatNeedsACommentAsksForItFirst(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('move', ['key' => 'MD-SPT-4', 'place' => 'in_progress']);
        self::assertStringContainsString('Refuse', (string) $mover->render());
        self::assertSame('to_test', $this->reloaded('MD-SPT-4')->getPlace(), 'nothing applied yet');

        $mover->call('confirm');
        self::assertStringContainsString('comment is required', (string) $mover->render());

        $mover->set('comment', 'The file is still empty.')->call('confirm');
        self::assertSame('in_progress', $this->reloaded('MD-SPT-4')->getPlace());
        self::assertStringNotContainsString('role="dialog"', (string) $mover->render());
    }

    public function testTheAgencyDropsWithoutTheCommentAskedOfClients(): void
    {
        $mover = $this->mover('thomas.girard@cairn.test');

        $mover->call('move', ['key' => 'MD-SPT-4', 'place' => 'in_progress']);

        self::assertSame('in_progress', $this->reloaded('MD-SPT-4')->getPlace(), 'applied at once');
        self::assertStringNotContainsString('role="dialog"', (string) $mover->render());
    }

    public function testCancellingTheDialogAppliesNothingAndPutsTheCardBack(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');
        $mover->call('move', ['key' => 'MD-SPT-4', 'place' => 'in_progress']);

        $mover->call('cancel');
        $render = $mover->render();

        self::assertSame('to_test', $this->reloaded('MD-SPT-4')->getPlace());
        self::assertSame(['from' => 'to_test', 'to' => 'in_progress'], $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? null);
    }

    public function testAClientAppliesOnlyHerOwnTransitions(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('move', ['key' => 'MD-SPT-1', 'place' => 'qualified']);
        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace(), 'qualifying is for the agency');

        $mover->call('move', ['key' => 'MD-SPT-4', 'place' => 'done']);
        self::assertSame('done', $this->reloaded('MD-SPT-4')->getPlace());
    }

    private function mover(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:BoardMove')->actingAs($this->scene->user($email));
    }

    private function reloaded(string $key): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Issue::class)->findOneBy(['key' => $key]) ?? throw new \LogicException(\sprintf('No %s.', $key));
    }
}

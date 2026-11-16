<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Board;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
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
final class MoveTest extends KernelTestCase
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
        self::assertSame(['key' => 'MD-SPT-1', 'applied' => true, 'top' => true, 'places' => ['new', 'needs_info', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'], 'forced' => ['new', 'needs_info', 'blocked', 'to_test', 'to_release', 'done']], $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? null, 'the card learns where it may go from its new place, and how');
        self::assertSame(JournalEntryType::Transitioned, self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->reloaded('MD-SPT-1')], ['id' => 'DESC'])?->getType(), 'the action, not a forced move');
        self::assertNotNull($mover->getEmittedEvent($render, 'issue:changed'));
    }

    public function testAPlaceNoTransitionReachesIsRefusedAndTheCardGoesBack(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('move', ['key' => 'MD-SPT-1', 'place' => 'done']);
        $render = $mover->render();

        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace());
        self::assertSame(['key' => 'MD-SPT-1', 'applied' => false, 'top' => false, 'places' => [], 'forced' => []], $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? null);
        self::assertNotNull($mover->getDispatchedBrowserEvent($render, 'toast:show'));
    }

    public function testAPlaceNoActionLeadsToTakesTheCardOutsideTheWorkflowForWhoeverMay(): void
    {
        $mover = $this->mover('thomas.girard@cairn.test');

        $mover->call('move', ['key' => 'MD-SPT-1', 'place' => 'done']);
        $render = $mover->render();

        self::assertSame('done', $this->reloaded('MD-SPT-1')->getPlace(), 'issue.force_place, without a dialog');
        $moved = $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? [];
        self::assertTrue($moved['applied'] ?? false);
        self::assertContains('in_progress', $moved['places'] ?? [], '"Reopen" leads there');
        self::assertNotContains('in_progress', $moved['forced'] ?? []);
        self::assertContains('qualified', $moved['forced'] ?? [], 'no action leads there from "Closed"');
        self::assertSame(['message' => 'MD-SPT-1 moved to Done.'], $mover->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
        self::assertNotNull($mover->getEmittedEvent($render, 'issue:changed'));
    }

    public function testATransitionThatNeedsACommentAsksForItFirst(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('move', ['key' => 'MD-SPT-4', 'place' => 'in_progress']);
        self::assertStringContainsString('Refuse', (string) $mover->render());
        self::assertSame('to_test', $this->reloaded('MD-SPT-4')->getPlace(), 'nothing applied yet');

        $mover->call('confirm');
        $render = $mover->render();
        self::assertStringContainsString('comment is required', (string) $render);
        self::assertNull($mover->getDispatchedBrowserEvent($render, 'board:moved'), 'the card waits where it was dropped while the dialog is open');

        $mover->set('comment', 'The file is still empty.')->call('confirm');
        self::assertSame('in_progress', $this->reloaded('MD-SPT-4')->getPlace());
        self::assertStringNotContainsString('role="dialog"', (string) $mover->render());
    }

    public function testAnInternalUserDropsWithoutTheCommentAskedOfClients(): void
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
        self::assertSame(['key' => 'MD-SPT-4', 'applied' => false, 'top' => false, 'places' => [], 'forced' => []], $mover->getDispatchedBrowserEvent($render, 'board:moved')['payload'] ?? null);
    }

    public function testAClientAppliesOnlyHerOwnTransitions(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('move', ['key' => 'MD-SPT-1', 'place' => 'qualified']);
        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace(), 'qualifying is for internal users');

        $mover->call('move', ['key' => 'MD-SPT-4', 'place' => 'to_release']);
        self::assertSame('to_release', $this->reloaded('MD-SPT-4')->getPlace());
    }

    public function testACardDroppedBetweenTwoOthersIsPlacedThereByWhoeverMayRank(): void
    {
        $this->scene->bug('Third');
        $mover = $this->mover('nadia.benali@cairn.test');
        $before = $this->column('new');

        $mover->call('move', ['key' => 'MD-SPT-3', 'place' => 'new', 'above' => $before[0], 'below' => $before[1]]);
        $render = $mover->render();

        self::assertFalse($mover->getDispatchedBrowserEvent($render, 'board:moved')['payload']['top'] ?? null, 'it stays where it was dropped');
        self::assertSame([$before[0], 'MD-SPT-3', ...\array_slice($before, 1)], $this->column('new'));
    }

    public function testACardMovedByWhoeverMayNotRankGoesOnTop(): void
    {
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('move', ['key' => 'MD-SPT-5', 'place' => 'in_progress', 'above' => 'MD-SPT-3']);
        $render = $mover->render();

        self::assertTrue($mover->getDispatchedBrowserEvent($render, 'board:moved')['payload']['top'] ?? null);
        self::assertSame(['MD-SPT-5', 'MD-SPT-3'], $this->column('in_progress'));
    }

    public function testACardIsRankedWithinItsColumn(): void
    {
        $before = $this->column('new');
        $mover = $this->mover('nadia.benali@cairn.test');

        $mover->call('rank', ['key' => $before[0], 'above' => $before[1]]);
        $render = $mover->render();

        self::assertSame([$before[1], $before[0]], $this->column('new'));
        self::assertTrue($mover->getDispatchedBrowserEvent($render, 'board:moved')['payload']['applied'] ?? null);
        self::assertNull($mover->getDispatchedBrowserEvent($render, 'toast:show'), 'nothing to announce');
        self::assertNull($mover->getEmittedEvent($render, 'issue:changed'));
    }

    public function testARankRefusedPutsTheCardBack(): void
    {
        $before = $this->column('new');
        $mover = $this->mover('claire.martin@maison-dupont.test');

        $mover->call('rank', ['key' => $before[0], 'above' => $before[1]]);
        $render = $mover->render();

        self::assertSame($before, $this->column('new'));
        self::assertFalse($mover->getDispatchedBrowserEvent($render, 'board:moved')['payload']['applied'] ?? null);
        self::assertNotNull($mover->getDispatchedBrowserEvent($render, 'toast:show'));
    }

    /**
     * The keys of a column of the bug kanban, as an internal user reads it.
     *
     * @return list<string>
     */
    private function column(string $place): array
    {
        $render = (string) $this->createLiveComponent('Board:Column', ['tracker' => $this->scene->bugs, 'placeId' => $place])
            ->actingAs($this->scene->user('thomas.girard@cairn.test'))
            ->render();
        preg_match_all('/data-board-key="([^"]+)"/', $render, $matches);

        return $matches[1];
    }

    private function mover(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Board:Move')->actingAs($this->scene->user($email));
    }

    private function reloaded(string $key): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Issue::class)->findOneBy(['key' => $key]) ?? throw new \LogicException(\sprintf('No %s.', $key));
    }
}

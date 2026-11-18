<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Tracker;

use App\Entity\Tracker;
use App\Repository\TrackerRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The shop has Bug, Feature and the private Internal task, in that
 * order; Thomas manages it, Nadia develops on it.
 */
final class OrderTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheManagerDragsATrackerToTheTop(): void
    {
        $component = $this->createLiveComponent('Tracker:Order', ['project' => $this->scene->shop])->actingAs($this->scene->user('thomas.girard@cairn.test'));
        $render = (string) $component->render();
        self::assertStringContainsString('Internal task', $render, 'private trackers are listed too');

        $component->call('move', ['tracker' => $this->scene->tasks->getId(), 'position' => 0]);

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame(['Internal task', 'Bug', 'Feature'], $this->names());
    }

    public function testTheListIsDrawnOnlyForWhoConfiguresTheProject(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Tracker:Order', ['project' => $this->scene->shop])->actingAs($this->scene->user('nadia.benali@cairn.test'))->render();
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(
            static fn (Tracker $tracker): string => $tracker->getName(),
            self::getContainer()->get(TrackerRepository::class)->findByProject($this->scene->shop, includeArchived: true),
        );
    }
}

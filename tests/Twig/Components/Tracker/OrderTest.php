<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Tracker;

use App\Entity\Tracker;
use App\Repository\TrackerRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The shop has Anomalie, Évolution and the private Tâche interne, in that
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
        self::assertStringContainsString('Tâche interne', $render, 'private trackers are listed too');

        $component->call('move', ['tracker' => $this->scene->tasks->getId(), 'position' => 0]);

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame(['Tâche interne', 'Anomalie', 'Évolution'], $this->names());
    }

    public function testARefusalIsShownInPlace(): void
    {
        $component = $this->createLiveComponent('Tracker:Order', ['project' => $this->scene->shop])->actingAs($this->scene->user('nadia.benali@cairn.test'));

        $component->call('move', ['tracker' => $this->scene->tasks->getId(), 'position' => 0]);

        self::assertStringContainsString('role="alert"', (string) $component->render());
        self::assertSame(['Anomalie', 'Évolution', 'Tâche interne'], $this->names());
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(
            static fn (Tracker $tracker): string => $tracker->getName(),
            self::getContainer()->get(TrackerRepository::class)->findByProject($this->scene->shop, includePrivate: true, includeInactive: true),
        );
    }
}

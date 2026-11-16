<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Template;

use App\Entity\ProjectTemplate;
use App\Entity\TemplateTracker;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Message\CommandBus;
use App\Repository\TemplateTrackerRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The trackers of a project template in their order. Julie administers
 * the instance, Thomas manages a project.
 */
final class TrackerOrderTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private ProjectTemplate $template;

    /** @var array<string, TemplateTracker> */
    private array $trackers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->template = $this->template('Web project', ['Bug' => 'bug', 'Feature' => 'evolution', 'Internal task' => 'task']);
    }

    public function testAnAdministratorDragsATrackerToTheTop(): void
    {
        $component = $this->createLiveComponent('Template:TrackerOrder', ['projectTemplate' => $this->template])->actingAs($this->scene->user('julie.admin@cairn.test'));
        self::assertStringContainsString('Internal task', (string) $component->render());

        $component->call('move', ['tracker' => $this->trackers['Internal task']->getId(), 'position' => 0]);

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame(['Internal task', 'Bug', 'Feature'], $this->names());
    }

    public function testATrackerOfAnotherTemplateIsNotMovedFromHere(): void
    {
        $this->template('Other', ['Support' => 'bug']);
        $component = $this->createLiveComponent('Template:TrackerOrder', ['projectTemplate' => $this->template])->actingAs($this->scene->user('julie.admin@cairn.test'));

        $this->expectException(NotFoundHttpException::class);
        $component->call('move', ['tracker' => $this->trackers['Support']->getId(), 'position' => 0]);
    }

    public function testOnlyAnAdministratorSeesAndArrangesThem(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Template:TrackerOrder', ['projectTemplate' => $this->template])->actingAs($this->scene->user('thomas.girard@cairn.test'))->render();
    }

    /**
     * @param array<string, string> $trackers name => workflow
     */
    private function template(string $name, array $trackers): ProjectTemplate
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $template = $commands->dispatch(new CreateTemplate($name));
        \assert($template instanceof ProjectTemplate);
        foreach ($trackers as $trackerName => $workflow) {
            $tracker = $commands->dispatch(new CreateTemplateTracker((int) $template->getId(), $trackerName, $workflow));
            \assert($tracker instanceof TemplateTracker);
            $this->trackers[$trackerName] = $tracker;
        }

        return $template;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(
            static fn (TemplateTracker $tracker): string => $tracker->getName(),
            self::getContainer()->get(TemplateTrackerRepository::class)->findByTemplate($this->template),
        );
    }
}

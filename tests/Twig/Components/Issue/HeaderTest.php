<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Issue;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Issue\Header;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The header of MD-SPT-4 of the scene, which Claire wrote and which waits
 * on "To test".
 */
final class HeaderTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->issue = $this->scene->issue('MD-SPT-4');
    }

    public function testTheHeaderFollowsAChangeMadeElsewhere(): void
    {
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $component = $this->createLiveComponent('Issue:Header', ['issue' => $this->issue])->actingAs($thomas);

        $before = (string) $component->render();
        self::assertStringContainsString('To test', $before, 'the last step of the path');
        self::assertStringNotContainsString('Private', $before);

        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateIssue((int) $thomas->getId(), 'MD-SPT-4', private: true));
        $component->emit('issue:changed');

        self::assertStringContainsString('Private', (string) $component->render());
    }

    public function testThePathIsTheOneTheJournalTells(): void
    {
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $component = $this->createLiveComponent('Issue:Header', ['issue' => $this->issue])->actingAs($thomas);
        $header = $component->component();
        self::assertInstanceOf(Header::class, $header);

        self::assertSame(['New', 'In progress', 'To test'], array_column($header->getPath(), 'label'));
        self::assertSame([false, false, false], array_column($header->getPath(), 'moved'));
        $render = (string) $component->render();
        self::assertStringContainsString('aria-label="Path"', $render);
        self::assertStringNotContainsString('Moved without an action', $render);

        self::getContainer()->get(CommandBus::class)->dispatch(new MoveIssue((int) $thomas->getId(), 'MD-SPT-4', 'qualified'));
        $component->emit('issue:changed');

        $header = $component->component();
        self::assertInstanceOf(Header::class, $header);
        self::assertSame(['label' => 'Qualified', 'moved' => true], $header->getPath()[3]);
        self::assertStringContainsString('Moved without an action', (string) $component->render());
    }

    public function testThePencilFollowsTheRightToEditTheIssue(): void
    {
        foreach (['claire.martin@maison-dupont.test' => true, 'thomas.girard@cairn.test' => true, 'nadia.benali@cairn.test' => false] as $email => $expected) {
            $render = (string) $this->createLiveComponent('Issue:Header', ['issue' => $this->issue])->actingAs($this->scene->user($email))->render();
            self::assertSame($expected, str_contains($render, 'data-live-action-param="editTitle"'), $email);
        }
    }

    public function testTheTitleIsEditedInPlace(): void
    {
        $component = $this->createLiveComponent('Issue:Header', ['issue' => $this->issue])->actingAs($this->scene->user('claire.martin@maison-dupont.test'));

        $component->call('editTitle')->set('title', '')->call('saveTitle');
        self::assertStringContainsString('The title is required', (string) $component->render());

        $component->set('title', 'The CSV export of orders is empty since Monday')->call('saveTitle');
        $render = $component->render();
        self::assertStringContainsString('since Monday</h1>', (string) $render);
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
    }
}

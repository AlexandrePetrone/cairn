<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Issue;
use App\Message\Command\Issue\ChangeVisibility;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The header of MD-SPT-4 of the scene, which Claire wrote and which waits
 * on « À recetter ».
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
        self::assertStringContainsString('La correction est en préproduction', $before, 'the hint of « À recetter »');
        self::assertStringNotContainsString('Privée', $before);

        self::getContainer()->get(CommandBus::class)->dispatch(new ChangeVisibility((int) $thomas->getId(), 'MD-SPT-4', true));
        $component->emit('issue:changed');

        self::assertStringContainsString('Privée', (string) $component->render());
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
        self::assertStringContainsString('Le titre est obligatoire', (string) $component->render());

        $component->set('title', 'L\'export CSV des commandes est vide depuis lundi')->call('saveTitle');
        $render = $component->render();
        self::assertStringContainsString('depuis lundi</h1>', (string) $render);
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
    }
}

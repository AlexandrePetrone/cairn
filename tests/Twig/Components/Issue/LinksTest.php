<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\IssueLink;
use App\Message\Command\Issue\AddLink;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * On MD-SPT-4 of the scene: Nadia develops on the shop, Claire is its
 * client, MD-SPT-6 is an internal task.
 */
final class LinksTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testADeveloperLinksTheIssueFromItsSide(): void
    {
        $component = $this->links('nadia.benali@cairn.test');
        $component->call('open');
        self::assertStringContainsString('Blocked by', (string) $component->render(), 'the inverse labels are offered too');

        $component->set('kind', 'blocks:inverse')->set('otherKey', 'export');
        self::assertStringNotContainsString('data-live-key-param="MD-SPT-4"', (string) $component->render(), 'the issue itself is not suggested');
        $component->set('otherKey', 'md-spt-1')->call('add');
        $render = $component->render();

        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertStringContainsString('MD-SPT-1', (string) $render);
        $link = $this->onlyLink();
        self::assertSame('MD-SPT-1', $link->getSource()->getKey(), 'MD-SPT-1 blocks MD-SPT-4');
        self::assertSame('blocks', $link->getTypeId());
    }

    public function testARefusalIsToldInItsOwnWords(): void
    {
        $component = $this->links('nadia.benali@cairn.test');

        $component->call('open')->set('kind', 'related')->set('otherKey', 'MD-SPT-99')->call('add');

        self::assertStringContainsString('No issue MD-SPT-99 that you can see', (string) $component->render());
    }

    public function testAClientReadsTheLinksHeSeesWithoutControls(): void
    {
        $nadia = (int) $this->scene->user('nadia.benali@cairn.test')->getId();
        $commands = self::getContainer()->get(CommandBus::class);
        $commands->dispatch(new AddLink($nadia, 'MD-SPT-4', 'related', 'MD-SPT-3'));
        $commands->dispatch(new AddLink($nadia, 'MD-SPT-4', 'related', 'MD-SPT-6'));

        $render = (string) $this->links('claire.martin@maison-dupont.test')->render();

        self::assertStringContainsString('MD-SPT-3', $render);
        self::assertStringNotContainsString('MD-SPT-6', $render, 'the internal task is not hers to see');
        self::assertStringNotContainsString('data-live-action-param="open"', $render);
        self::assertStringNotContainsString('confirmRemove', $render);
    }

    public function testALinkIsRemovedAfterConfirmingInPlace(): void
    {
        $nadia = (int) $this->scene->user('nadia.benali@cairn.test')->getId();
        $link = self::getContainer()->get(CommandBus::class)->dispatch(new AddLink($nadia, 'MD-SPT-4', 'related', 'MD-SPT-3'));
        self::assertInstanceOf(IssueLink::class, $link);
        $component = $this->links('nadia.benali@cairn.test');

        $component->call('confirmRemove', ['id' => $link->getId()]);
        self::assertStringContainsString('Remove this link?', (string) $component->render());
        $component->call('remove');

        self::assertStringNotContainsString('MD-SPT-3', (string) $component->render());
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(IssueLink::class)->count([]));
    }

    private function links(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Links', ['issue' => $this->scene->issue('MD-SPT-4')])->actingAs($this->scene->user($email));
    }

    private function onlyLink(): IssueLink
    {
        $links = self::getContainer()->get(EntityManagerInterface::class)->getRepository(IssueLink::class)->findAll();
        self::assertCount(1, $links);

        return $links[0];
    }
}

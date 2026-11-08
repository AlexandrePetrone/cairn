<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\IssueSubscription;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * On MD-SPT-4 of the scene: Claire wrote it, so she follows it; Léa
 * develops on the shop but took no part in it.
 */
final class FollowTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheAuthorFollowsAndMayStop(): void
    {
        $component = $this->follow('claire.martin@maison-dupont.test');
        self::assertStringContainsString('Unfollow', (string) $component->render());

        $component->call('unfollow');
        $render = $component->render();

        self::assertStringContainsString('data-live-action-param="follow"', (string) $render);
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertNull($this->subscription('claire.martin@maison-dupont.test'));
    }

    public function testSomeoneElseStartsFollowing(): void
    {
        $component = $this->follow('lea.fontaine@cairn.test');
        self::assertStringContainsString('data-live-action-param="follow"', (string) $component->render());

        $component->call('follow');

        self::assertStringContainsString('Unfollow', (string) $component->render());
        self::assertNotNull($this->subscription('lea.fontaine@cairn.test'));
    }

    private function follow(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Follow', ['issue' => $this->scene->issue('MD-SPT-4')])->actingAs($this->scene->user($email));
    }

    private function subscription(string $email): ?IssueSubscription
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(IssueSubscription::class)
            ->findOneBy(['issue' => $this->scene->issue('MD-SPT-4'), 'user' => $this->scene->user($email)]);
    }
}

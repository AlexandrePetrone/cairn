<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * MD-SPT-4 of the scene was written by Claire, is assigned to Nadia, who
 * commented, as did Thomas, who manages the workspace: the three follow
 * it; Léa and Paul do not.
 */
final class SubscribersTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    public function testTheSubscribersAreListed(): void
    {
        $scene = ShopScene::load(self::getContainer());

        $component = $this->createLiveComponent('Issue:Subscribers', ['issue' => $scene->issue('MD-SPT-4')])
            ->actingAs($scene->user('paul.roussel@maison-dupont.test'));
        $render = (string) $component->render();
        self::assertStringContainsString('title="Claire Martin"', $render, 'folded at first: the avatars on the header');

        foreach (['Claire Martin', 'Nadia Benali', 'Thomas Girard'] as $name) {
            self::assertStringContainsString($name, $render);
        }
        self::assertStringNotContainsString('Léa Fontaine', $render);
        self::assertStringNotContainsString('Paul Roussel', $render);
    }
}

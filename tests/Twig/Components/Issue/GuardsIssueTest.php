<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Tests\Scene\ShopScene;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The components of the page of an issue answer on an address of their
 * own, with the props the page gave them: each asks again who draws it.
 * MD-SPT-6 is an internal task, which Claire, the client, does not see.
 */
final class GuardsIssueTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    #[DataProvider('components')]
    public function testAnIssueOneMayNotSeeIsNotDrawn(string $component): void
    {
        $private = $this->createLiveComponent($component, ['issue' => $this->scene->issue('MD-SPT-6')]);
        self::assertNotSame('', (string) $private->actingAs($this->scene->user('thomas.girard@cairn.test'))->render());

        $this->expectException(NotFoundHttpException::class);
        $this->createLiveComponent($component, ['issue' => $this->scene->issue('MD-SPT-6')])->actingAs($this->scene->user('claire.martin@maison-dupont.test'))->render();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function components(): iterable
    {
        foreach (['Description', 'Header', 'Properties', 'Fields', 'Files', 'Subscribers', 'Links', 'Follow'] as $name) {
            yield $name => ['Issue:'.$name];
        }
    }
}

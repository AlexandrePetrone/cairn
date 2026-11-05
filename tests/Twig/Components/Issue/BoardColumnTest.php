<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Tracker;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Issue\BoardColumn;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The columns of the bug kanban of the scene: MD-SPT-1 and 2 are new
 * (blocking and low), 3 is in progress, 4 waits for acceptance, 5 is done.
 */
final class BoardColumnTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAColumnShowsTheIssuesOfItsPlaceWithWhereEachMayGo(): void
    {
        $render = (string) $this->column('new', 'thomas.girard@cairn.test')->render();

        self::assertStringContainsString('data-board-key="MD-SPT-1"', $render);
        self::assertStringContainsString('data-board-key="MD-SPT-2"', $render);
        self::assertStringNotContainsString('MD-SPT-3', $render);
        self::assertStringContainsString('data-board-places="qualified rejected"', $render);
    }

    public function testACardTheViewerCannotMoveIsLocked(): void
    {
        $render = (string) $this->column('new', 'claire.martin@maison-dupont.test')->render();

        self::assertStringContainsString('data-board-places=""', $render);
        self::assertStringContainsString('board-locked', $render);
    }

    public function testTheFiltersReceivedFromThePageApply(): void
    {
        $render = (string) $this->column('new', 'thomas.girard@cairn.test', ['criticality' => ['blocking']])->render();

        self::assertStringContainsString('MD-SPT-1', $render);
        self::assertStringNotContainsString('MD-SPT-2', $render);
    }

    public function testAColumnGrowsByBatches(): void
    {
        foreach (range(1, BoardColumn::BATCH + 5) as $index) {
            $this->scene->bug("Anomalie {$index}");
        }
        $column = $this->column('new', 'thomas.girard@cairn.test');

        self::assertSame(BoardColumn::BATCH, substr_count((string) $column->render(), 'data-board-key='));
        self::assertStringContainsString('Afficher plus (7)', (string) $column->render());

        $column->call('more');
        self::assertSame(BoardColumn::BATCH + 7, substr_count((string) $column->render(), 'data-board-key='));
        self::assertStringNotContainsString('Afficher plus', (string) $column->render());
    }

    public function testAFoldedColumnIsARailThatCountsItsIssuesUntilUnfolded(): void
    {
        $column = $this->column('done', 'thomas.girard@cairn.test', ['open' => false]);
        $render = (string) $column->render();
        self::assertStringNotContainsString('data-board-key="MD-SPT-5"', $render);
        self::assertStringContainsString('w-10', $render);
        self::assertMatchesRegularExpression('#>\s*1\s*</span>#', $render, 'the rail still counts its issues');
        self::assertStringContainsString('data-board-place-value="done"', $render, 'a card can still be dropped on it');

        $column->call('toggle');
        $render = (string) $column->render();
        self::assertStringContainsString('data-board-key="MD-SPT-5"', $render);
        self::assertStringContainsString('w-72', $render);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function column(string $place, string $email, array $filters = []): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:BoardColumn', ['tracker' => $this->bugs(), 'placeId' => $place, ...$filters])
            ->actingAs($this->scene->user($email));
    }

    private function bugs(): Tracker
    {
        return $this->scene->bugs;
    }
}

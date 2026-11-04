<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Tests\Scene\ShopScene;
use App\Workflow\PlaceCatalog;
use App\Workflow\PlaceChoice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Under the example configuration: `bug`, `evolution` and `task`, whose
 * places share ids and labels ("En cours" is `in_progress` for bugs and
 * evolutions, `doing` for tasks) yet stay apart, each with its workflow.
 */
final class PlaceCatalogTest extends KernelTestCase
{
    private PlaceCatalog $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        ShopScene::load(self::getContainer());
        $this->catalog = self::getContainer()->get(PlaceCatalog::class);
    }

    public function testThePlacesOfAWorkflowComeInItsOrder(): void
    {
        self::assertSame(['bug:new', 'bug:qualified', 'bug:in_progress', 'bug:to_test', 'bug:done', 'bug:rejected'], self::keys($this->catalog->choices(['bug'])));
    }

    public function testTheWorkflowsOfAListKeepTheirPlacesApart(): void
    {
        $choices = $this->catalog->choices(['task', 'bug']);

        self::assertSame(['bug:new', 'bug:qualified', 'bug:in_progress', 'bug:to_test', 'bug:done', 'bug:rejected', 'task:todo', 'task:doing', 'task:done'], self::keys($choices), 'workflow by workflow, in the order of the instance file');
        self::assertSame('En cours', $choices[2]->label);
        self::assertSame('Anomalie', $choices[2]->workflowLabel);
        self::assertSame('En cours', $choices[7]->label, 'the same label, another place');
    }

    public function testEveryPlaceOfTheInstanceHasARank(): void
    {
        $ranks = $this->catalog->ranks();

        self::assertSame(1, $ranks['bug:new']);
        self::assertLessThan($ranks['bug:done'], $ranks['bug:to_test']);
        self::assertLessThan($ranks['evolution:new'], $ranks['bug:rejected'], 'the workflows follow one another');
        self::assertSame(self::keys($this->catalog->choices()), array_keys($ranks));
    }

    /**
     * @param list<PlaceChoice> $choices
     *
     * @return list<string>
     */
    private static function keys(array $choices): array
    {
        return array_map(static fn (PlaceChoice $choice): string => $choice->key, $choices);
    }
}

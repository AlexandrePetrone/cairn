<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Import;

use App\Entity\Import;
use App\Import\ImportMapping;
use App\Tests\Import\ArchiveBuilder;
use App\Tests\Import\ImportsArchives;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The correspondences of the example archive (see ArchiveBuilder)
 * imported into the shop of the scene: Bug and Feature go to the
 * trackers of the same name, Support to a tracker to create on the bug
 * workflow.
 */
final class MappingTest extends KernelTestCase
{
    use ImportsArchives;
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->directory = $this->importsDirectory();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheStepsFollowTheTrackersWithoutSavingAnything(): void
    {
        $import = $this->import();
        $component = $this->component($import);

        $render = (string) $component->render();
        self::assertStringContainsString('Bugs: statuses to the steps of &quot;Bug&quot;', $render);
        self::assertStringNotContainsString('Internal task', strip_tags((string) preg_replace('#<select.*?</select>#s', '', $render)));
        self::assertStringContainsString('name="import_mapping[trackers][support][name]"', $render, 'a tracker to create has a name');
        self::assertStringNotContainsString('name="import_mapping[trackers][bug][name]"', $render, 'an existing tracker has none');

        $render = (string) $component->set('import_mapping.trackers.support.target', 'new:task')->render();
        self::assertStringContainsString('Bugs: statuses to the steps of &quot;Internal task&quot;', $render, 'the statuses of Support are now placed in the task workflow');
        self::assertMatchesRegularExpression('#name="import_mapping\[places\]\[task\]\[bugs\]\[in_progress\]".*?<option value="[a-z_]+" selected#s', $render, 'with a guessed place');

        $render = (string) $component->set('import_mapping.trackers.support.target', 'tracker:'.$this->scene->bugs->getId())->render();
        self::assertStringNotContainsString('name="import_mapping[trackers][support][name]"', $render);
        self::assertStringNotContainsString('steps of &quot;Internal task&quot;', $render);

        self::assertSame('new:bug', ImportMapping::fromArray($this->reloadImport($import)->getMapping())->trackers['support']['target'], 'nothing is kept before saving');
    }

    public function testSavingKeepsTheChoicesAndLeadsToTheAccounts(): void
    {
        $import = $this->import();
        $component = $this->component($import);
        $component->set('import_mapping.trackers.support.target', 'new:task');
        $component->set('import_mapping.trackers.support.name', 'Helpdesk');
        $component->set('import_mapping.fields.module', ImportMapping::IGNORE);
        $component->call('save');

        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame('/admin/imports/'.$import->getId().'/accounts', $component->response()->headers->get('Location'));
        $mapping = ImportMapping::fromArray($this->reloadImport($import)->getMapping());
        self::assertSame(['target' => 'new:task', 'name' => 'Helpdesk'], $mapping->trackers['support']);
        self::assertSame(['in_progress'], array_keys($mapping->places['task']['bugs']));
        self::assertSame(['new'], array_keys($mapping->places['bug']['bugs']), 'the statuses of Support left the bug workflow');
        self::assertSame(ImportMapping::IGNORE, $mapping->fields['module']);
    }

    public function testATrackerToCreateNeedsAName(): void
    {
        $import = $this->import();
        $component = $this->component($import);
        $component->set('import_mapping.trackers.support.name', '');

        try {
            $component->call('save');
            self::fail('A tracker to create without a name was saved.');
        } catch (UnprocessableEntityHttpException) {
        }
        self::assertSame('Support', ImportMapping::fromArray($this->reloadImport($import)->getMapping())->trackers['support']['name']);
    }

    public function testAnArchiveWithNumericIdsIsDrawnAndSaved(): void
    {
        $import = $this->import(ArchiveBuilder::numericIds());
        $component = $this->component($import);

        $render = (string) $component->set('import_mapping.trackers.3.target', 'new:task')->render();
        self::assertStringContainsString('Bugs: statuses to the steps of &quot;Internal task&quot;', $render);
        $component->call('save');

        self::assertSame(302, $component->response()->getStatusCode());
        $mapping = ImportMapping::fromArray($this->reloadImport($import)->getMapping());
        self::assertSame('new:task', $mapping->trackers['3']['target']);
        self::assertSame(['2'], array_map(strval(...), array_keys($mapping->places['task']['1'])));
    }

    public function testTheMappingIsDrawnOnlyForAnAdministrator(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Import:Mapping', ['import' => $this->import()])->actingAs($this->scene->user('thomas.girard@cairn.test'))->render();
    }

    private function import(?callable $change = null): Import
    {
        return $this->analysedImport($this->directory, (int) $this->scene->user('julie.admin@cairn.test')->getId(), (int) $this->scene->shop->getId(), $change);
    }

    private function component(Import $import): TestLiveComponent
    {
        return $this->createLiveComponent('Import:Mapping', ['import' => $import])->actingAs($this->scene->user('julie.admin@cairn.test'));
    }
}

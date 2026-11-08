<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\User;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Issue\IssueList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The list of the shop of the scene, seen by Nadia, who develops on it.
 */
final class IssueListTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    private const array ALL = ['place', 'tracker', 'criticality', 'assignee'];

    public function testTheListOffersTheFiltersThePageAsksFor(): void
    {
        $render = (string) $this->list(self::ALL)->render();

        self::assertStringContainsString('Endless spinner on the cart page', $render);
        self::assertStringNotContainsString('Blurry product image on mobile', $render, 'closed issues are hidden by default');
        self::assertStringContainsString('data-model="places"', $render);
        self::assertStringContainsString('data-model="trackers"', $render);
        self::assertStringContainsString('data-model="assignees"', $render);
        self::assertStringNotContainsString('data-model="projects"', $render, 'not offered inside a project');

        $render = (string) $this->list(['place', 'criticality'])->render();
        self::assertStringNotContainsString('data-model="assignees"', $render);
    }

    public function testThePlacesOfferedAreThoseOfTheWorkflowsOfTheList(): void
    {
        $project = $this->list(self::ALL);
        $places = $this->placeIds($project);
        self::assertContains('bug:in_progress', $places);
        self::assertContains('task:doing', $places, 'the same label in another workflow is another place');
        self::assertContains('evolution:to_estimate', $places);
        $render = (string) $project->render();
        self::assertStringContainsString('>Bug</p>', $render, 'the places of a list mixing workflows come under their workflow');

        $bugs = $this->createLiveComponent('Issue:List', ['project' => $this->scene->shop, 'tracker' => $this->scene->bugs, 'dimensions' => ['place'], 'listPath' => '/MD/SPT/issues/tracker/1'])
            ->actingAs($this->user('nadia.benali@cairn.test'));
        self::assertSame(['bug:new', 'bug:qualified', 'bug:in_progress', 'bug:to_test', 'bug:done', 'bug:rejected'], $this->placeIds($bugs), 'the bug workflow, in its order');
        self::assertStringNotContainsString('>Bug</p>', (string) $bugs->render(), 'no workflow heading for one workflow');
    }

    public function testAGroupPerPlaceOfEachWorkflow(): void
    {
        $component = $this->list(self::ALL);

        $component->set('places', ['bug:in_progress', 'task:doing'])->set('group', 'place');

        $render = (string) $component->render();
        self::assertStringContainsString('MD-SPT-3', $render, 'a bug in progress');
        self::assertStringContainsString('Upgrade Symfony to 8.1', $render, 'a task doing');
        self::assertSame(2, substr_count($render, 'scope="rowgroup"'), 'one group per workflow');
        self::assertStringContainsString('Bug › In progress', $render);
        self::assertStringContainsString('Internal task › In progress', $render);
    }

    public function testAFilterRedrawsTheListFromItsFirstPage(): void
    {
        $component = $this->list(self::ALL);
        $component->set('page', 2);

        $component->set('places', ['bug:done']);

        $render = (string) $component->render();
        self::assertStringContainsString('Blurry product image on mobile', $render, 'a finished place chosen is listed');
        self::assertStringNotContainsString('Endless spinner on the cart page', $render);
        self::assertSame(1, $this->state($component)->page);

        $component->set('places', [])->set('q', 'cart');
        $render = (string) $component->render();
        self::assertStringContainsString('Endless spinner on the cart page', $render);
        self::assertStringNotContainsString('Typo in the footer', $render);

        $component->call('clearFilters');
        self::assertStringContainsString('Typo in the footer', (string) $component->render());
    }

    public function testAColumnHeaderSortsAscendingThenDescendingThenNoMore(): void
    {
        $component = $this->list(self::ALL);

        $component->call('sortBy', ['column' => 'title']);
        self::assertSame(['title', 'asc'], [$this->state($component)->sort, $this->state($component)->order]);
        $render = (string) $component->render();
        self::assertStringContainsString('aria-sort=ascending', $render);
        self::assertLessThan(strpos($render, 'Typo in the footer'), strpos($render, 'Add payment in three instalments'), 'A before T');

        $component->call('sortBy', ['column' => 'title']);
        self::assertSame(['title', 'desc'], [$this->state($component)->sort, $this->state($component)->order]);

        $component->call('sortBy', ['column' => 'title']);
        self::assertSame('', $this->state($component)->sort, 'back to the default order');

        $component->call('sortBy', ['column' => 'project']);
        self::assertSame('', $this->state($component)->sort, 'no project column inside a project');
    }

    public function testAGroupedListShowsEachGroupWithItsTotalAndFoldsIt(): void
    {
        $component = $this->list(self::ALL);

        $component->set('group', 'assignee');

        $render = (string) $component->render();
        self::assertStringContainsString('Unassigned', $render);
        self::assertStringContainsString('Nadia Benali', $render);
        self::assertStringContainsString('Endless spinner on the cart page', $render);

        $component->call('toggleGroup', ['key' => 'none']);
        $render = (string) $component->render();
        self::assertStringContainsString('aria-expanded="false"', $render);
        self::assertStringNotContainsString('Endless spinner on the cart page', $render, 'the rows of a folded group are not drawn');

        $component->call('clearSelection');
        self::assertStringNotContainsString('Endless spinner on the cart page', (string) $component->render(), 'it stays folded while the list redraws');

        $component->set('group', 'criticality');
        self::assertSame([], $this->state($component)->collapsed, 'another grouping opens every group');

        $component->set('group', 'project');
        self::assertSame('', $this->state($component)->group, 'no grouping by project inside a project');
    }

    public function testCheckedRowsLeadToTheSelectionPageAndBack(): void
    {
        $component = $this->list(self::ALL);
        $component->set('places', ['bug:new', 'evolution:new', 'evolution:to_estimate']);

        $component->call('togglePage');

        $state = $this->state($component);
        self::assertCount(4, $state->selected, 'the new issues and the one to estimate');
        $render = (string) $component->render();
        self::assertStringContainsString('aria-label="Select the whole page" checked', $render, 'the header box says the page is checked');
        self::assertStringContainsString('4 issues selected', $render);
        $url = $state->getSelectionUrl();
        self::assertStringStartsWith('/MD/SPT/issues/selection?', $url, 'the selection page of the project');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $parameters);
        self::assertSame(array_map(strval(...), $state->selected), $parameters['ids'] ?? null);
        self::assertSame('/MD/SPT/issues?'.http_build_query(['places' => ['bug:new', 'evolution:new', 'evolution:to_estimate']]), $parameters['from'] ?? null, 'back to the list as it was filtered');

        $component->set('q', 'cart');
        self::assertSame([], $this->state($component)->selected, 'a filter forgets the selection');

        $component->call('togglePage')->call('togglePage');
        self::assertSame([], $this->state($component)->selected, 'the header box unchecks the page');
    }

    public function testAListAcrossProjectsLeadsToTheApplicationsSelectionPage(): void
    {
        $component = $this->createLiveComponent('Issue:List', ['assigneeId' => $this->user('nadia.benali@cairn.test')->getId(), 'dimensions' => ['place', 'project', 'criticality'], 'showProject' => true, 'listPath' => '/issues/assigned'])
            ->actingAs($this->user('nadia.benali@cairn.test'));

        $component->call('togglePage');

        $list = $this->state($component);
        self::assertNotSame([], $list->selected);
        self::assertStringStartsWith('/issues/selection?', $list->getSelectionUrl());
    }

    public function testValuesTheListDoesNotKnowAreDropped(): void
    {
        $component = $this->list(self::ALL);

        $component->set('places', ['bug:done', 'done', 'not a place'])->set('assignees', ['0', 'x'])->set('page', -3)->set('sort', 'nonsense')->set('order', 'sideways');

        $state = $this->state($component);
        self::assertSame(['bug:done'], $state->places, 'a place is named with its workflow');
        self::assertSame([0], $state->assignees);
        self::assertSame(1, $state->page);
        self::assertSame('', $state->sort);
        self::assertSame('asc', $state->order);
    }

    /**
     * @return list<string>
     */
    private function placeIds(TestLiveComponent $component): array
    {
        preg_match_all('/<input[^>]*data-model="places"[^>]*>/', (string) $component->render(), $inputs);

        return array_map(static fn (string $input): string => preg_match('/value="([^"]+)"/', $input, $value) ? $value[1] : '', $inputs[0]);
    }

    /**
     * @param list<string> $dimensions
     */
    private function list(array $dimensions): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:List', [
            'project' => $this->scene->shop,
            'dimensions' => $dimensions,
            'listPath' => '/MD/SPT/issues',
        ])->actingAs($this->user('nadia.benali@cairn.test'));
    }

    private function state(TestLiveComponent $component): IssueList
    {
        $list = $component->component();
        self::assertInstanceOf(IssueList::class, $list);

        return $list;
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In the scene, the Maison Dupont shop (MD-SPT) has public bugs and
 * evolutions, a private "Internal task" tracker, Claire as client and
 * Nadia as developer; Sophie is a client of another workspace.
 */
final class IssueListTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAClientSeesThePublicOpenIssuesOfTheirProject(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Issues');
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
        self::assertSelectorTextNotContains('tbody', 'Upgrade Symfony to 8.1', 'private tracker');
        self::assertSelectorTextNotContains('tbody', 'Blurry product image on mobile', 'closed issues are hidden by default');
        self::assertSelectorTextNotContains('aside', 'Internal task', 'private trackers are not in the navigation');
        self::assertSelectorTextContains('header', 'Claire Martin');
    }

    public function testInternalUsersSeePrivateIssuesAndTrackers(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Upgrade Symfony to 8.1');
        self::assertSelectorTextContains('aside', 'Internal task');
    }

    public function testFiltersComeFromTheQueryStringSoAFilteredListIsAnAddress(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues?places[]=bug:done');
        self::assertSelectorTextContains('tbody', 'Blurry product image on mobile');
        self::assertSelectorTextNotContains('tbody', 'Endless spinner on the cart page');

        self::assertSelectorExists('form#issue-filters input[type="radio"][name="closed"][value=""][checked]', 'open issues unless the address says all');

        $this->client->request('GET', '/MD/SPT/issues?closed=1&q=cart');
        self::assertSelectorExists('form#issue-filters input[type="radio"][name="closed"][value="1"][checked]');
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
        self::assertSelectorTextNotContains('tbody', 'Typo in the footer');

        $this->client->request('GET', '/MD/SPT/issues?assignees[]=0');
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
        self::assertSelectorTextNotContains('tbody', 'Shipping costs are not shown for Corsica', 'assigned to Nadia');

        $this->client->request('GET', '/MD/SPT/issues?assignees[]='.$this->scene->user('nadia.benali@cairn.test')->getId());
        self::assertSelectorTextContains('tbody', 'Shipping costs are not shown for Corsica');
        self::assertSelectorTextNotContains('tbody', 'Endless spinner on the cart page');

        $this->client->request('GET', '/MD/SPT/issues?places[]=nonsense&page=0&sort=nonsense&group=nonsense');
        self::assertResponseIsSuccessful('a value the list does not know is ignored');
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
    }

    public function testUsersSeePlacesNeverStatuses(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertSelectorTextContains('[role="search"]', 'Step');
        self::assertSelectorTextNotContains('[role="search"]', 'Status');
        self::assertSelectorTextContains('tbody', 'In progress');
        self::assertSelectorTextNotContains('tbody', '· Open', 'the status of a place is not shown');
    }

    public function testAListIsSortedAndGroupedFromItsAddress(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues?sort=title&order=desc&group=assignee');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('th[aria-sort="descending"]');
        self::assertSelectorTextContains('tbody th', 'Unassigned', 'unassigned issues come first');
    }

    public function testTheListOffersTheFiltersItsRouteAsksFor(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues');
        $form = $crawler->filter('form#issue-filters[method="get"][action="/MD/SPT/issues"][data-turbo-frame="issues"]');
        self::assertCount(1, $form, 'the filters are a GET form sent to the frame of the results');
        self::assertGreaterThan(0, $form->filter('input[name="places[]"]')->count());
        self::assertGreaterThan(0, $form->filter('input[name="trackers[]"]')->count());
        self::assertGreaterThan(0, $form->filter('input[name="assignees[]"]')->count());
        self::assertCount(0, $form->filter('input[name="projects[]"]'), 'not offered inside a project');
        self::assertCount(0, $form->filter('input[name="view"]'), 'only a tracker has a kanban');

        $crawler = $this->client->request('GET', '/MD/SPT/issues/assigned');
        self::assertCount(0, $crawler->filter('input[name="assignees[]"]'));
    }

    public function testThePlacesOfferedAreThoseOfTheWorkflowsOfTheList(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues');
        $places = $crawler->filter('input[name="places[]"]')->extract(['value']);
        self::assertContains('bug:in_progress', $places);
        self::assertContains('task:doing', $places, 'the same label in another workflow is another place');
        self::assertContains('evolution:to_estimate', $places);
        self::assertSelectorTextContains('[role="search"]', 'Internal task', 'the places of a list mixing workflows come under their workflow');

        $crawler = $this->client->request('GET', '/MD/SPT/issues/tracker/'.$this->scene->bugs->getId());
        self::assertSame(['bug:new', 'bug:qualified', 'bug:in_progress', 'bug:to_test', 'bug:done', 'bug:rejected'], $crawler->filter('input[name="places[]"]')->extract(['value']), 'the bug workflow, in its order');

        $this->client->request('GET', '/MD/SPT/issues?places[]=evolution:to_estimate&places[]=unknown:place');
        self::assertSelectorTextContains('tbody', 'Monthly accounting export in FEC format');
        self::assertSelectorTextNotContains('tbody', 'Endless spinner on the cart page', 'a place of no workflow of the list is ignored, the others filter');
    }

    public function testAGroupPerPlaceOfEachWorkflowWithItsTotal(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?places[]=bug:in_progress&places[]=task:doing&group=place');

        self::assertCount(2, $crawler->filter('th[scope="rowgroup"]'), 'one group per workflow');
        self::assertSelectorTextContains('tbody', 'Bug › In progress');
        self::assertSelectorTextContains('tbody', 'Internal task › In progress');
        self::assertCount(2, $crawler->filter('th[scope="rowgroup"] button[aria-expanded="true"][data-action="fold#toggle"]'), 'groups fold in the browser');
        self::assertSame(['bug:in_progress', 'task:doing'], $crawler->filter('tr[data-fold-group]')->each(static fn ($row): string => (string) $row->attr('data-fold-group')), 'each row says its group');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?group=project');
        self::assertCount(0, $crawler->filter('th[scope="rowgroup"]'), 'no grouping by project inside a project');
    }

    public function testAColumnHeaderLeadsToTheListSortedAscendingThenDescendingThenNoMore(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?criticality[]=low&page=1');
        $header = $crawler->filter('th a[data-turbo-frame="issues"]')->reduce(static fn ($link): bool => 'Title' === trim($link->text()));
        self::assertSame('/MD/SPT/issues?criticality%5B%5D=low&sort=title', $header->attr('href'), 'the filters stay');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?sort=title');
        self::assertSelectorExists('th[aria-sort="ascending"]');
        self::assertSelectorExists('th a[href="/MD/SPT/issues?sort=title&order=desc"]');
        $titles = $crawler->filter('tbody tr')->each(static fn ($row): string => $row->text());
        self::assertStringContainsString('Add payment in three instalments', $titles[0], 'A first');
        self::assertSelectorExists('input[type="hidden"][form="issue-filters"][name="sort"][value="title"]', 'a filter keeps the order');

        $this->client->request('GET', '/MD/SPT/issues?sort=title&order=desc');
        self::assertSelectorExists('th[aria-sort="descending"] a[href="/MD/SPT/issues"]', 'back to the default order');

        $this->client->request('GET', '/MD/SPT/issues?sort=project');
        self::assertSelectorNotExists('th[aria-sort]', 'no project column inside a project');
    }

    public function testCheckedRowsAreSentToTheSelectionPageWithTheWayBack(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?places[]=bug:new&q=cart');

        $form = $crawler->filter('form#issue-selection[method="get"][action="/MD/SPT/issues/selection"]');
        self::assertCount(1, $form, 'the selection page of the project');
        self::assertSame('/MD/SPT/issues?places%5B%5D=bug%3Anew&q=cart', $form->filter('input[name="from"]')->attr('value'), 'back to the list as it was filtered');
        self::assertSame([(string) $this->scene->issue('MD-SPT-1')->getId()], $crawler->filter('input[form="issue-selection"][name="ids[]"]')->extract(['value']), 'each row is a box of that form');

        $crawler = $this->client->request('GET', '/issues/assigned');
        self::assertCount(1, $crawler->filter('form#issue-selection[action="/issues/selection"]'), 'the application\'s across projects');
    }

    public function testAChangeOfTheListAsksForTheFrameOfItsResultsOnly(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?q=cart', server: ['HTTP_TURBO_FRAME' => 'issues']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#issues'));
        self::assertSelectorTextContains('turbo-frame#issues', 'Endless spinner on the cart page');
        self::assertSelectorTextNotContains('turbo-frame#issues', 'Typo in the footer');
        self::assertSelectorNotExists('form#issue-filters', 'the filters are not drawn again');
        self::assertSelectorNotExists('aside', 'nor the page');
    }

    public function testATrackerShowsAsAKanbanUnderTheSameFilters(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $url = '/MD/SPT/issues/tracker/'.$this->scene->bugs->getId();

        $crawler = $this->client->request('GET', $url);
        self::assertCount(2, $crawler->filter('form#issue-filters input[type="radio"][name="view"]'), 'the list and the kanban are two views of the form');
        self::assertSelectorExists('turbo-frame#issues table');

        $crawler = $this->client->request('GET', $url.'?view=board&criticality[]=blocking&criticality[]=unknown&assignees[]=0&q=cart');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="view"][value="board"][checked]');
        self::assertSelectorExists('input[name="criticality[]"][value="blocking"][checked]');
        self::assertSelectorNotExists('turbo-frame#issues table');
        $columns = $crawler->filter('turbo-frame#issues [data-live-name-value="Board:Column"][loading="lazy"]');
        self::assertCount(6, $columns, 'one per place of the bug workflow, none rendered yet');
        self::assertCount(1, $crawler->filter('turbo-frame#issues [data-live-name-value="Board:Move"]'));
        $props = (string) $columns->first()->attr('data-live-props-value');
        self::assertStringContainsString('"criticality":["blocking"]', $props, 'an unknown value is dropped');
        self::assertStringContainsString('"assignees":[0]', $props);
        self::assertStringContainsString('"q":"cart"', $props);

        $this->client->request('GET', '/MD/SPT/issues?view=board');
        self::assertSelectorExists('turbo-frame#issues table', 'a list across trackers has no kanban');
    }

    public function testEachListIsARouteWithItsOwnScopeAndFilters(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorTextContains('[role="search"]', 'Assignee');
        self::assertSelectorTextContains('[role="search"]', 'Nadia Benali', 'developer on the shop');
        self::assertSelectorTextContains('[role="search"]', 'Claire Martin', 'client of the workspace');
        self::assertSelectorTextNotContains('[role="search"]', 'Sophie Bernard', 'client of another workspace');
        self::assertSelectorTextNotContains('[role="search"]', 'Marc Petit', 'developer of the intranet only');

        $this->client->request('GET', '/MD/SPT/issues/assigned');
        self::assertSelectorTextContains('h1', 'Issues assigned to me');
        self::assertSelectorTextContains('tbody', 'Shipping costs are not shown for Corsica');
        self::assertSelectorTextNotContains('tbody', 'Endless spinner on the cart page', 'unassigned');
        self::assertSelectorTextNotContains('[role="search"]', 'Assignee', 'the scope makes the filter pointless');
        self::assertSelectorTextContains('aside [aria-current="page"]', 'Assigned to me');

        $this->client->request('GET', '/MD/SPT/issues/created');
        self::assertSelectorTextContains('h1', 'Issues created by me');
        self::assertSelectorExists('.rounded-lg.border-dashed', 'Nadia reported nothing on this project');
    }

    public function testATrackerListIsScopedToItAndDropsTheTrackerFilter(): void
    {
        $tasks = $this->scene->tasks;
        $url = '/MD/SPT/issues/tracker/'.$tasks->getId();

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Internal task');
        self::assertSelectorTextContains('tbody', 'Upgrade Symfony to 8.1');
        self::assertSelectorTextNotContains('tbody', 'Endless spinner on the cart page', 'another tracker');
        self::assertSelectorTextNotContains('[role="search"]', 'Category', 'the scope makes the filter pointless');
        self::assertSelectorTextContains('aside [aria-current="page"]', 'Internal task');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'a private tracker does not exist for externals');

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', '/MD/INT/issues/tracker/'.$tasks->getId());
        self::assertResponseStatusCodeSame(404, 'a tracker of another project');
    }

    public function testAClientOfAnotherWorkspaceIsRefused(): void
    {
        $this->signInAs('sophie.bernard@travelia.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownProjectIsNotFound(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/ZZZ/issues');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheProjectsOfAnArchivedWorkspaceSayTheyAreReadOnly(): void
    {
        $this->scene->dupont->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The workspace Maison Dupont is archived');
        self::assertSelectorNotExists('a[href="/MD/SPT/issues/new"]', 'no new issue');
        self::assertSelectorNotExists('a[href="/MD/SPT/settings"]', 'no settings, even for the manager');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

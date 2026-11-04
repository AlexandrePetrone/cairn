<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In the scene, the Maison Dupont shop (MD-SPT) has public bugs and
 * evolutions, a private "Tâche interne" tracker, Claire as client and
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
        self::assertSelectorTextContains('h1', 'Demandes');
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
        self::assertSelectorTextNotContains('tbody', 'Mettre à jour Symfony en 8.1', 'private tracker');
        self::assertSelectorTextNotContains('tbody', 'Image produit floue sur mobile', 'closed issues are hidden by default');
        self::assertSelectorTextNotContains('aside', 'Tâche interne', 'private trackers are not in the navigation');
        self::assertSelectorTextContains('header', 'Claire Martin');
    }

    public function testInternalUsersSeePrivateIssuesAndTrackers(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Mettre à jour Symfony en 8.1');
        self::assertSelectorTextContains('aside', 'Tâche interne');
    }

    public function testFiltersComeFromTheQueryStringSoAFilteredListIsAnAddress(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues?places[]=bug:done');
        self::assertSelectorTextContains('tbody', 'Image produit floue sur mobile');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier');

        $this->client->request('GET', '/MD/SPT/issues?closed=1&q=panier');
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
        self::assertSelectorTextNotContains('tbody', 'Typo dans le pied de page');

        $this->client->request('GET', '/MD/SPT/issues?assignees[]=0');
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
        self::assertSelectorTextNotContains('tbody', 'Les frais de port ne s\'affichent pas en Corse', 'assigned to Nadia');

        $this->client->request('GET', '/MD/SPT/issues?assignees[]='.$this->scene->user('nadia.benali@cairn.test')->getId());
        self::assertSelectorTextContains('tbody', 'Les frais de port ne s\'affichent pas en Corse');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier');

        $this->client->request('GET', '/MD/SPT/issues?places[]=nonsense&page=0&sort=nonsense&group=nonsense');
        self::assertResponseIsSuccessful('a value the list does not know is ignored');
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
    }

    public function testUsersSeePlacesNeverStatuses(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertSelectorTextContains('[role="search"]', 'Étape');
        self::assertSelectorTextNotContains('[role="search"]', 'Statut');
        self::assertSelectorTextContains('tbody', 'En cours');
        self::assertSelectorTextNotContains('tbody', '· Ouvert', 'the status of a place is not shown');
    }

    public function testAListIsSortedAndGroupedFromItsAddress(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues?sort=title&order=desc&group=assignee');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('th[aria-sort="descending"]');
        self::assertSelectorTextContains('tbody th', 'Non assigné', 'unassigned issues come first');
    }

    public function testEachListIsARouteWithItsOwnScopeAndFilters(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorTextContains('[role="search"]', 'Assigné');
        self::assertSelectorTextContains('[role="search"]', 'Nadia Benali', 'developer on the shop');
        self::assertSelectorTextContains('[role="search"]', 'Claire Martin', 'client of the workspace');
        self::assertSelectorTextNotContains('[role="search"]', 'Sophie Bernard', 'client of another workspace');
        self::assertSelectorTextNotContains('[role="search"]', 'Marc Petit', 'developer of the intranet only');

        $this->client->request('GET', '/MD/SPT/issues/assigned');
        self::assertSelectorTextContains('h1', 'Demandes assignées à moi');
        self::assertSelectorTextContains('tbody', 'Les frais de port ne s\'affichent pas en Corse');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier', 'unassigned');
        self::assertSelectorTextNotContains('[role="search"]', 'Assigné', 'the scope makes the filter pointless');
        self::assertSelectorTextContains('aside [aria-current="page"]', 'Assignées à moi');

        $this->client->request('GET', '/MD/SPT/issues/created');
        self::assertSelectorTextContains('h1', 'Demandes créées par moi');
        self::assertSelectorExists('.rounded-lg.border-dashed', 'Nadia reported nothing on this project');
    }

    public function testATrackerListIsScopedToItAndDropsTheTrackerFilter(): void
    {
        $tasks = $this->scene->tasks;
        $url = '/MD/SPT/issues/tracker/'.$tasks->getId();

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tâche interne');
        self::assertSelectorTextContains('tbody', 'Mettre à jour Symfony en 8.1');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier', 'another tracker');
        self::assertSelectorTextNotContains('[role="search"]', 'Catégorie', 'the scope makes the filter pointless');
        self::assertSelectorTextContains('aside [aria-current="page"]', 'Tâche interne');

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
        self::assertSelectorTextContains('body', 'L\'espace Maison Dupont est archivé');
        self::assertSelectorNotExists('a[href="/MD/SPT/issues/new"]', 'no new issue');
        self::assertSelectorNotExists('a[href="/MD/SPT/settings"]', 'no settings, even for the manager');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

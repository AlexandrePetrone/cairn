<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Tests\Scene\ShopScene;
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

    public function testFiltersComeFromTheQueryString(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues?status[]=closed');
        self::assertSelectorTextContains('tbody', 'Image produit floue sur mobile');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier');

        $this->client->request('GET', '/MD/SPT/issues?closed=1&q=panier');
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
        self::assertSelectorTextNotContains('tbody', 'Typo dans le pied de page');

        $this->client->request('GET', '/MD/SPT/issues?assignee[]=0');
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
        self::assertSelectorTextNotContains('tbody', 'Les frais de port ne s\'affichent pas en Corse', 'assigned to Nadia');

        $this->client->request('GET', '/MD/SPT/issues?assignee[]='.$this->scene->user('nadia.benali@cairn.test')->getId());
        self::assertSelectorTextContains('tbody', 'Les frais de port ne s\'affichent pas en Corse');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier');

        $this->client->request('GET', '/MD/SPT/issues?status[]=nonsense');
        self::assertResponseStatusCodeSame(404, 'a value outside the enum');

        $this->client->request('GET', '/MD/SPT/issues?page=0');
        self::assertResponseStatusCodeSame(404, 'an invalid page');
    }

    public function testEachListIsARouteWithItsOwnScopeAndFilters(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorTextContains('form', 'Assigné');
        self::assertSelectorTextContains('form', 'Nadia Benali', 'developer on the shop');
        self::assertSelectorTextContains('form', 'Claire Martin', 'client of the workspace');
        self::assertSelectorTextNotContains('form', 'Sophie Bernard', 'client of another workspace');
        self::assertSelectorTextNotContains('form', 'Marc Petit', 'developer of the intranet only');

        $this->client->request('GET', '/MD/SPT/issues/assigned');
        self::assertSelectorTextContains('h1', 'Demandes assignées à moi');
        self::assertSelectorTextContains('tbody', 'Les frais de port ne s\'affichent pas en Corse');
        self::assertSelectorTextNotContains('tbody', 'Spinner infini sur la page panier', 'unassigned');
        self::assertSelectorTextNotContains('form', 'Assigné', 'the scope makes the filter pointless');
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
        self::assertSelectorTextNotContains('form', 'Catégorie', 'the scope makes the filter pointless');
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

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

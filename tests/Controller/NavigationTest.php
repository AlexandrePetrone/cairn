<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The pages across projects. In the scene, Claire (client of Maison
 * Dupont) wrote most issues of the shop, MD-SPT-4 waits for her
 * acceptance; Nadia develops on the shop, MD-SPT-3, 4 and 6 are hers,
 * 6 being an internal task; Sophie is the client of Travelia, whose
 * only project is archived.
 */
final class NavigationTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheHomePageGathersMyProjectsAndMyIssues(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Claire');
        self::assertSelectorTextContains('main', 'Site e-commerce');
        self::assertSelectorTextContains('main', 'Intranet');
        self::assertSelectorTextNotContains('main', 'Site de réservation', 'not her workspace');
        self::assertSelectorTextContains('main section.border-amber-200', 'L\'export CSV des commandes est vide', 'her issue waits for her');
        self::assertSelectorTextContains('main section.border-amber-200', 'Votre demande est en attente');
        self::assertSelectorTextContains('main', 'Créées par moi');
        self::assertSelectorTextContains('main', 'Spinner infini sur la page panier');
    }

    public function testMyIssuesListAcrossProjectsWhatIsVisible(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/issues/assigned');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Les frais de port ne s\'affichent pas en Corse');
        self::assertSelectorTextContains('tbody', 'Mettre à jour Symfony en 8.1', 'internal users see the internal tasks');
        self::assertSelectorTextContains('tbody', 'Site e-commerce', 'the project column');
        self::assertSelectorTextNotContains('tbody', 'Typo dans le pied de page', 'not hers');

        $this->client->request('GET', '/issues/assigned', ['projects' => [(int) $this->scene->intranet->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('tbody', 'nothing of hers on the intranet');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/issues/created');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Spinner infini sur la page panier');
        self::assertSelectorTextNotContains('tbody', 'Export comptable mensuel au format FEC', 'written by Thomas');
    }

    public function testTheProjectsPageGroupsWhatTheViewerMayOpen(): void
    {
        $this->signInAs('sophie.bernard@travelia.test');
        $this->client->request('GET', '/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Site de réservation');
        self::assertSelectorTextContains('main', 'Archivé · lecture seule');
        self::assertSelectorTextNotContains('main', 'Site e-commerce');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/projects');
        self::assertSelectorTextContains('main', 'Maison Dupont');
        self::assertSelectorTextContains('main', 'Site de réservation', 'internal users see every project');

        $this->client->request('GET', '/projects', ['q' => 'intra']);
        self::assertSelectorTextContains('main', 'Intranet');
        self::assertSelectorTextNotContains('main', 'Site e-commerce');
    }

    public function testTheTabsAreForTheApplicationLevelAndTheProjectSwitcherForProjects(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/projects');
        self::assertSelectorExists('header nav a[href="/issues/assigned"]');
        self::assertSelectorExists('header nav a[href="/projects"]');
        self::assertSelectorNotExists('header [data-controller="list-filter"]');

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('header nav', 'no tabs inside a project');
        self::assertSelectorExists('header [data-controller="list-filter"] a[href="/MD/INT/issues"]', 'the other project of her workspace');
        self::assertSelectorNotExists('header [data-controller="list-filter"] a[href="/TR/RES/issues"]', 'a project she may not open');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

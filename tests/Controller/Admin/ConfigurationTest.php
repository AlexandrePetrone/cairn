<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The configuration pages, on the example configuration the scene
 * applies: four roles, three workflows, the shop's ten issues.
 */
final class ConfigurationTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testOnlyAdministratorsReadIt(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));

        $this->client->request('GET', '/admin/configuration/roles');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheRolesAndTheirRightsAreExplained(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration');
        self::assertResponseRedirects('/admin/configuration/roles');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('aside', 'Rôles et droits', 'the configuration has its own section in the navigation');
        self::assertSelectorTextContains('main', 'Chef de projet');
        self::assertSelectorTextContains('main', 'Internes seulement');
        self::assertSelectorTextContains('main', '1 accès', 'Thomas on Maison Dupont');

        $assign = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Assigner les demandes'));
        self::assertCount(1, $assign);
        // Chef de projet, Développeur, Client, Client (lecture), Administrateur
        self::assertSame([true, true, false, false, true], $assign->filter('td')->slice(1)->each(static fn ($cell): bool => $cell->filter('svg')->count() > 0));
    }

    public function testAWorkflowReadsPlaceByPlaceWithItsUsage(): void
    {
        $this->signInAsAdmin();

        $crawler = $this->client->request('GET', '/admin/configuration/workflows');
        self::assertSelectorExists('#bug [data-controller="workflow-diagram"]');
        self::assertStringContainsString('to_test -.->|"Refuser 💬"| in_progress', $crawler->filter('#bug [data-workflow-diagram-target="source"]')->text(), 'the graph, drawn by the browser');
        self::assertSelectorTextContains('#bug', 'Anomalie');
        self::assertSelectorTextContains('#bug', '1 catégorie');
        self::assertSelectorTextContains('#bug', 'Environnement');
        self::assertSelectorTextContains('#bug', 'Obligatoire');
        self::assertSelectorTextContains('#bug', 'Expliquez ce qui ne va pas', 'the comment prompt of Refuser');

        $accept = $crawler->filter('#bug tbody tr')->reduce(static fn ($row): bool => str_starts_with(trim($row->text()), 'Valider'));
        self::assertCount(1, $accept);
        self::assertStringContainsString('À recetter', $accept->text());
        // Chef de projet, Développeur, Client, Client (lecture), Administrateur
        self::assertSame([true, false, true, false, true], $accept->filter('td')->slice(3)->each(static fn ($cell): bool => $cell->filter('svg')->count() > 0));

        $this->client->request('GET', '/admin/configuration/workflows/bug');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Une nouvelle demande commence à l\'étape « Nouveau ».');
        self::assertSelectorTextContains('main', 'Commentaire obligatoire');
        self::assertSelectorTextContains('main table', 'Site e-commerce');

        $this->client->request('GET', '/admin/configuration/workflows/nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFieldsLinksAndHistoryAreListed(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/fields');
        self::assertSelectorTextContains('main', 'Préproduction');
        self::assertSelectorTextContains('main', 'Anomalie (obligatoire)');
        self::assertSelectorTextContains('main', 'Dupliqué par');

        $this->client->request('GET', '/admin/configuration/history');
        self::assertSelectorTextContains('main', 'Stricte');
        self::assertSelectorTextContains('main', 'En vigueur');
        self::assertSelectorTextContains('main', 'par tests');
    }

    private function signInAsAdmin(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
    }
}

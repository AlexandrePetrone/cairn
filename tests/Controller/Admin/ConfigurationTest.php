<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ConfigSnapshot;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;

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

        self::assertSelectorTextContains('aside', 'Roles and rights', 'the configuration has its own section in the navigation');
        self::assertSelectorTextContains('main', 'Project manager');
        self::assertSelectorTextContains('main', 'Internal users only');
        self::assertSelectorTextContains('main', '1 access', 'Thomas on Maison Dupont');

        $assign = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Assign issues'));
        self::assertCount(1, $assign);
        // Project manager, Developer, Client, Client (read-only), Administrator
        self::assertSame([true, true, false, false, true], $assign->filter('td')->slice(1)->each(static fn ($cell): bool => $cell->filter('svg')->count() > 0));
    }

    public function testAWorkflowReadsPlaceByPlaceWithItsUsage(): void
    {
        $this->signInAsAdmin();

        $crawler = $this->client->request('GET', '/admin/configuration/workflows');
        self::assertSelectorExists('#bug [data-controller="workflow-diagram"]');
        self::assertStringContainsString('to_test -.->|"Refuse 💬"| in_progress', $crawler->filter('#bug [data-workflow-diagram-target="source"]')->text(), 'the graph, drawn by the browser');
        self::assertSelectorTextContains('#bug', 'Bug');
        self::assertSelectorTextContains('#bug', '1 tracker');
        self::assertSelectorTextContains('#bug', 'Environment');
        self::assertSelectorTextContains('#bug', 'Required');
        self::assertSelectorTextContains('#bug', 'Explain what is wrong', 'the comment prompt of Refuse');

        $accept = $crawler->filter('#bug tbody tr')->reduce(static fn ($row): bool => str_starts_with(trim($row->text()), 'Accept'));
        self::assertCount(1, $accept);
        self::assertStringContainsString('To test', $accept->text());
        // Project manager, Developer, Client, Client (read-only), Administrator
        self::assertSame([true, false, true, false, true], $accept->filter('td')->slice(3)->each(static fn ($cell): bool => $cell->filter('svg')->count() > 0));

        $this->client->request('GET', '/admin/configuration/workflows/bug');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'A new issue starts on "New".');
        self::assertSelectorTextContains('main', 'Comment required');
        self::assertSelectorTextContains('main table', 'Online shop');

        $this->client->request('GET', '/admin/configuration/workflows/nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFieldsLinksAndHistoryAreListed(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/fields');
        self::assertSelectorTextContains('main', 'Pre-production');
        self::assertSelectorTextContains('main', 'Bug (required)');
        self::assertSelectorTextContains('main', 'Duplicated by');

        $this->client->request('GET', '/admin/configuration/history');
        self::assertSelectorTextContains('main', 'Strict');
        self::assertSelectorTextContains('main', 'In force');
        self::assertSelectorTextContains('main', 'by tests');
    }

    /**
     * A sync of an unchanged definition, as a deployment makes, stores a
     * snapshot without any change to list and without anybody applying it.
     */
    public function testASyncWithoutChangesIsListedToo(): void
    {
        $this->signInAsAdmin();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ConfigSnapshot(hash('sha256', 'rewritten'), ['format' => 1], '', null, self::getContainer()->get(ClockInterface::class)->now()));
        $entityManager->flush();

        $this->client->request('GET', '/admin/configuration/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main ol li:first-child', 'No change in the definition');
        self::assertSelectorTextContains('main ol li:last-child', 'Show the');
    }

    private function signInAsAdmin(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
    }
}

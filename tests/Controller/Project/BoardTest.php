<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The kanban of a tracker of the shop: Thomas manages Maison Dupont,
 * Claire is its client, Sophie the client of another workspace.
 */
final class BoardTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageHasALazyColumnPerPlaceAndTheMover(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('[data-live-name-value="Issue:BoardColumn"][loading="lazy"]'), 'one per place of the bug workflow, none rendered yet');
        self::assertCount(1, $crawler->filter('[data-live-name-value="Issue:BoardMove"]'));
        self::assertSelectorExists(\sprintf('nav a[href="/MD/SPT/issues/tracker/%d"]', $this->scene->bugs->getId()));
    }

    public function testTheTrackerListLeadsToItsKanban(): void
    {
        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));
        $this->client->request('GET', \sprintf('/MD/SPT/issues/tracker/%d', $this->scene->bugs->getId()));

        self::assertSelectorExists(\sprintf('nav a[href="%s"]', $this->url()));
    }

    public function testTheFiltersOfTheAddressReachTheColumns(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $crawler = $this->client->request('GET', $this->url().'?criticality[]=blocking&criticality[]=unknown&assignees[]=0&q=panier');

        self::assertResponseIsSuccessful();
        $props = $crawler->filter('[data-live-name-value="Issue:BoardColumn"]')->first()->attr('data-live-props-value');
        self::assertIsString($props);
        self::assertStringContainsString('"criticality":["blocking"]', $props, 'an unknown value is dropped');
        self::assertStringContainsString('"assignees":[0]', $props);
        self::assertStringContainsString('"q":"panier"', $props);
    }

    public function testAPrivateTrackerOrOneOfAnotherProjectIsNotFound(): void
    {
        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));
        $this->client->request('GET', $this->url((int) $this->scene->tasks->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $this->client->request('GET', \sprintf('/MD/INT/issues/tracker/%d/board', $this->scene->bugs->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    private function url(?int $trackerId = null): string
    {
        return \sprintf('/MD/SPT/issues/tracker/%d/board', $trackerId ?? $this->scene->bugs->getId());
    }
}

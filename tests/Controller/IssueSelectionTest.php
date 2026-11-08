<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The selection page the lists send their checked rows to.
 */
final class IssueSelectionTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheSelectionComesFromTheListAndGoesBackToIt(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $this->client->request('GET', '/issues/selection', [
            'ids' => [(string) $this->scene->issue('MD-SPT-1')->getId(), (string) $this->scene->issue('MD-SPT-2')->getId(), 'nonsense'],
            'from' => '/MD/SPT/issues?status%5B0%5D=open',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bulk actions');
        self::assertSelectorTextContains('main', 'Endless spinner on the cart page');
        self::assertSelectorTextContains('main', 'Typo in the footer');
        self::assertSelectorTextContains('main', 'Qualify');
        self::assertSelectorExists('a[href="/MD/SPT/issues?status%5B0%5D=open"]', 'the way back to the list');
    }

    public function testAProjectsSelectionPageKeepsTheProjectsLayout(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $this->client->request('GET', '/MD/SPT/issues/selection', ['ids' => [(string) $this->scene->issue('MD-SPT-1')->getId()]]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('aside', 'Assigned to me', 'the navigation of the project');
        self::assertSelectorTextContains('nav[aria-label="Breadcrumb"]', 'Online shop');
        self::assertSelectorTextContains('nav[aria-label="Breadcrumb"]', 'Bulk actions');
        self::assertSelectorExists('main a[href="/MD/SPT/issues"]', 'back to the issues of the project by default');
        self::assertSelectorTextContains('main', 'Endless spinner on the cart page');
    }

    public function testAProjectsSelectionPageIsGuardedLikeTheProject(): void
    {
        $this->client->loginUser($this->scene->user('sophie.bernard@travelia.test'));
        $this->client->request('GET', '/MD/SPT/issues/selection');
        self::assertResponseStatusCodeSame(403, 'a client of another workspace');

        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));
        $this->client->request('GET', '/MD/ZZZ/issues/selection');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAPathOfThisSiteIsAWayBack(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $this->client->request('GET', '/issues/selection', ['ids' => [(string) $this->scene->issue('MD-SPT-1')->getId()], 'from' => '//evil.test/phishing']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="//evil.test/phishing"]');
        self::assertSelectorExists('main a[href="/"]', 'the home page instead');
    }

    public function testASelectionHoldsAHundredIssuesAtMost(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        // The issue first, then a hundred ids: the address keeps the first hundred.
        $first = (int) $this->scene->issue('MD-SPT-1')->getId();
        $this->client->request('GET', '/issues/selection', ['ids' => array_map(strval(...), range($first, $first + 100))]);

        self::assertResponseIsSuccessful('a longer address keeps the first ones');
        self::assertSelectorTextContains('main', 'Endless spinner on the cart page');
    }

    public function testAnEmptySelectionSaysSo(): void
    {
        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));

        $this->client->request('GET', '/issues/selection', ['ids' => [(string) $this->scene->issue('MD-SPT-6')->getId()]]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No issue selected', 'the internal task is not hers to see');
        self::assertSelectorTextNotContains('main', 'Upgrade Symfony to 8.1');
    }
}

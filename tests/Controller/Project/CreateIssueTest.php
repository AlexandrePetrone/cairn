<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The page only: the screen itself is covered by CreateFormTest.
 */
final class CreateIssueTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheNewIssuePageOffersTheTrackersTheViewerMayUse(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/issues/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"]');
        self::assertSelectorTextContains('main', 'Anomalie');
        self::assertSelectorTextNotContains('main', 'Tâche interne', 'private trackers are not offered');
    }

    public function testWhoMayNotCreateIsRefused(): void
    {
        $this->signInAs('sophie.bernard@travelia.test');

        $this->client->request('GET', '/MD/SPT/issues/new');
        self::assertResponseStatusCodeSame(403, 'no role on the project');

        $this->client->request('GET', '/TR/RES/issues/new');
        self::assertResponseStatusCodeSame(403, 'her project is archived');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

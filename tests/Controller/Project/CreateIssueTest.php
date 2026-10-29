<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Repository\UserRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The page only: the screen itself is covered by CreateFormTest.
 */
final class CreateIssueTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        AppStory::load();
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
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}

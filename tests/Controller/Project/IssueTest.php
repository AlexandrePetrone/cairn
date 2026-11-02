<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Comment;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In the scene, MD-SPT-4, « L'export CSV des commandes est vide », waits
 * for Claire's acceptance; MD-SPT-6 is an internal task.
 */
final class IssueTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAClientSeesTheIssueAndTheActionsOfHerRole(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/issues/4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'L\'export CSV des commandes est vide');
        self::assertSelectorTextContains('main aside', 'Environnement');
        self::assertSelectorTextContains('main', 'Valider');
        self::assertSelectorTextContains('main', 'Refuser');
        self::assertSelectorTextContains('main', 'a créé la demande');
        self::assertSelectorTextContains('main', 'a changé l\'assigné : Personne → Nadia Benali', 'assigned through the command in the fixtures');
    }

    public function testPrivateCommentsAreForInternalsOnly(): void
    {
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->scene->issue('MD-SPT-4'), 'private' => true]) ?? self::fail('no private comment');
        $url = '/MD/SPT/issues/4';

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('main', $comment->getBody());
        self::assertSelectorTextContains('main', 'Privé · invisible pour les externes');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', $comment->getBody());
    }

    public function testAnArchivedProjectShowsItsIssuesWithoutAnyControl(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorTextNotContains('body', 'est archivé', 'no banner on an active project');

        $this->scene->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach (['claire.martin@maison-dupont.test', 'julie.admin@cairn.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues/4');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Ce projet est archivé');
            self::assertSelectorTextContains('h1', 'L\'export CSV des commandes est vide');
            self::assertSelectorTextNotContains('main', 'Valider', $email);
            self::assertSelectorTextNotContains('main', 'Refuser', $email);
            self::assertSelectorNotExists('main [data-live-action-param="postComment"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="apply"]', $email);
        }
    }

    public function testAnIssueTheViewerMayNotSeeIsNotFound(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues/6');
        self::assertResponseStatusCodeSame(404, 'a private issue');

        $this->client->request('GET', '/MD/SPT/issues/999');
        self::assertResponseStatusCodeSame(404, 'no such number');

        $this->signInAs('sophie.bernard@travelia.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertResponseStatusCodeSame(403, 'no role on the project');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

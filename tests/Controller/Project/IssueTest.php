<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * On the development dataset: MD-SPT-4, « L'export CSV des commandes est
 * vide », waits for Claire's acceptance; MD-SPT-18 is an internal task.
 */
final class IssueTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        AppStory::load();
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
        $comment = $this->privateCommentOnAPublicShopIssue();
        $url = '/MD/SPT/issues/'.$comment->getIssue()->getNumber();

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('main', $comment->getBody());
        self::assertSelectorTextContains('main', 'Privé · invisible pour les externes');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', $comment->getBody());
    }

    public function testAnIssueTheViewerMayNotSeeIsNotFound(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues/18');
        self::assertResponseStatusCodeSame(404, 'a private issue');

        $this->client->request('GET', '/MD/SPT/issues/999');
        self::assertResponseStatusCodeSame(404, 'no such number');

        $this->signInAs('sophie.bernard@travelia.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertResponseStatusCodeSame(403, 'no role on the project');
    }

    private function privateCommentOnAPublicShopIssue(): Comment
    {
        $shop = self::getContainer()->get(ProjectRepository::class)->findOneByKeys('MD', 'SPT');
        $comments = self::getContainer()->get(EntityManagerInterface::class)->createQueryBuilder()
            ->select('c')
            ->from(Comment::class, 'c')
            ->join('c.issue', 'i')
            ->join('i.tracker', 't')
            ->where('c.private = true')
            ->andWhere('i.project = :shop')
            ->andWhere('i.private = false')
            ->andWhere('t.private = false')
            ->setParameter('shop', $shop)
            ->getQuery()
            ->getResult();
        self::assertIsArray($comments);
        self::assertNotEmpty($comments, 'the dataset has a private comment on a public shop issue');
        self::assertInstanceOf(Comment::class, $comments[0]);
        self::assertInstanceOf(Issue::class, $comments[0]->getIssue());

        return $comments[0];
    }

    private function signInAs(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}

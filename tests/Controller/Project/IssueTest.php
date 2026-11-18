<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Comment;
use App\Entity\Enum\MergeRequestState;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In the scene, MD-SPT-4, "The CSV export of orders is empty", waits
 * for Claire's acceptance; MD-SPT-6 is an internal task.
 */
final class IssueTest extends WebTestCase
{
    use KernelHelpers;

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
        self::assertSelectorTextContains('h1', 'The CSV export of orders is empty');
        self::assertSelectorTextContains('main aside', 'Environment');
        self::assertSelectorTextContains('main', 'Accept');
        self::assertSelectorTextContains('main', 'Refuse');
        self::assertSelectorTextContains('main', 'created the issue');
        self::assertSelectorTextContains('main', 'changed the assignee: Nobody → Nadia Benali', 'assigned through the command in the fixtures');
        self::assertSelectorTextContains('main [aria-label="Path"]', 'New');
        self::assertSelectorExists('main [data-live-name-value="Issue:Activity"] [data-live-transition-param="accept"]', 'her actions are in the response box');
        self::assertSelectorExists('main aside [data-live-action-param="transition"][data-live-id-param="accept"]', 'and in the step menu, where they lead');
        self::assertSelectorNotExists('main aside [data-live-action-param="transition"][data-live-id-param="refuse"]', 'but not the one that asks her for a comment');
        self::assertSelectorNotExists('main aside [data-live-action-param="move"]', 'she does not leave the workflow');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorExists('main aside [data-live-action-param="transition"][data-live-id-param="accept"]', 'the manager has the step menu');
        self::assertSelectorExists('main aside [data-live-action-param="move"][data-live-place-param="qualified"]', 'and may leave the workflow');
    }

    public function testPrivateCommentsAreForInternalsOnly(): void
    {
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->scene->issue('MD-SPT-4'), 'private' => true]) ?? self::fail('no private comment');
        $url = '/MD/SPT/issues/4';

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('main', $comment->getBody());
        self::assertSelectorTextContains('main', 'Private · hidden from external users');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', $comment->getBody());
    }

    public function testAnArchivedProjectShowsItsIssuesWithoutAnyControl(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorTextNotContains('body', 'is archived', 'no banner on an active project');

        $this->scene->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach (['claire.martin@maison-dupont.test', 'julie.admin@cairn.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues/4');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'This project is archived');
            self::assertSelectorTextContains('h1', 'The CSV export of orders is empty');
            // The workflow drawer still names the actions in its Mermaid source; the activity offers none.
            self::assertSelectorTextNotContains('main [data-live-name-value="Issue:Activity"]', 'Accept', $email);
            self::assertSelectorTextNotContains('main [data-live-name-value="Issue:Activity"]', 'Refuse', $email);
            self::assertSelectorNotExists('main [data-live-action-param="submit"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="apply"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="transition"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="move"]', $email);
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

    public function testTheCodeOfTheIssueIsForInternalsOnly(): void
    {
        $repository = (new ForgeTestSupport(self::getContainer()->get(EntityManagerInterface::class)))->link($this->scene->shop);
        $commands = self::commandBus();
        $commands->dispatch(new RecordCommits((int) $repository->getId(), 'MD-SPT-4', 'main', 'https://gitlab.test/maison-dupont/shop/-/tree/main', [ForgeTestSupport::commit('a1b2c3d4e5f6'), ForgeTestSupport::commit('e4f5a6b7c8d9', 'test(export): 8 000 orders')]));
        $commands->dispatch(new RecordMergeRequest((int) $repository->getId(), 'MD-SPT-4', ForgeTestSupport::mergeRequest(MergeRequestState::Merged)));
        $url = '/MD/SPT/issues/4';

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '2 commits on main');
        self::assertSelectorTextContains('main', 'Merge request !431');
        self::assertSelectorTextContains('main', 'Merged');
        self::assertSelectorTextContains('main aside', '!431', 'the card of the panel, folded, sums the code up');
        self::assertSelectorExists('main span[title="GitLab"]', 'the marker names the forge');
        self::assertSelectorExists('a[href="https://gitlab.test/maison-dupont/shop/-/commit/a1b2c3d4e5f6"]');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'maison-dupont/shop');
        self::assertSelectorTextNotContains('main', '!431');
        self::assertSelectorTextNotContains('body', 'a1b2c3d4');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\IssueList;

use App\Entity\Comment;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\User;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Bulk actions on the shop of the scene: MD-SPT-1 and 2 are new bugs, 4
 * waits for Claire's acceptance, 6 is an internal task. Nadia develops on
 * the shop, Thomas manages the workspace, Claire is its client, Marc
 * develops on the intranet only.
 */
final class SelectionTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private const string FROM = '/MD/SPT/issues?status%5B0%5D=open';

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageShowsTheIssuesAndTheActionsTheyShare(): void
    {
        $render = (string) $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2')->render();

        self::assertStringContainsString('MD-SPT-1', $render);
        self::assertStringContainsString('MD-SPT-2', $render);
        self::assertStringContainsString('data-live-choice-param="transition:qualify"', $render);
        self::assertStringContainsString('data-live-choice-param="assign"', $render);
        self::assertStringContainsString('data-live-choice-param="comment"', $render);

        $render = (string) $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-4', 'MD-SPT-6')->render();
        self::assertStringNotContainsString('MD-SPT-6', $render, 'an issue she may not see is left out');
        self::assertStringContainsString('data-live-choice-param="transition:accept"', $render);
        self::assertStringNotContainsString('data-live-choice-param="assign"', $render);
    }

    public function testAProductionReleaseMovesEveryIssueWithTheSameComment(): void
    {
        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('choose', ['choice' => 'transition:qualify'])
            ->set('comment', 'Reproduced, to fix in the next batch.')
            ->call('apply');

        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame(self::FROM, $component->response()->headers->get('Location'), 'back to the list as it was');
        foreach (['MD-SPT-1', 'MD-SPT-2'] as $key) {
            $issue = $this->reloaded($key);
            self::assertSame('qualified', $issue->getPlace());
            $comments = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findBy(['issue' => $issue]);
            self::assertCount(1, $comments, 'one comment on each issue');
            self::assertSame('Reproduced, to fix in the next batch.', $comments[0]->getBody());
        }
    }

    public function testATransitionThatAsksForACommentIsRefusedWithout(): void
    {
        $component = $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-4');

        $component->call('choose', ['choice' => 'transition:refuse'])->call('apply');

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertStringContainsString('Explain what is wrong', (string) $component->render(), 'in the words of the configuration');
        self::assertSame('to_test', $this->reloaded('MD-SPT-4')->getPlace());
    }

    public function testAManagerAssignsSeveralIssuesAtOnce(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('choose', ['choice' => 'assign'])->set('assigneeId', $lea->getId())->call('apply');

        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded('MD-SPT-1')->getAssignee()?->getEmail());
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded('MD-SPT-2')->getAssignee()?->getEmail());
    }

    public function testAClientSetsTheCriticalityOfSeveralIssues(): void
    {
        $component = $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('choose', ['choice' => 'criticality'])->set('criticality', 'high')->call('apply');

        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame(Criticality::High, $this->reloaded('MD-SPT-1')->getCriticality());
        self::assertSame(Criticality::High, $this->reloaded('MD-SPT-2')->getCriticality());
    }

    public function testADeveloperSchedulesSeveralIssuesInARelease(): void
    {
        $release = $this->scene->release();
        $intranetBug = $this->scene->intranetBug();
        self::assertStringNotContainsString('data-live-choice-param="release"', (string) $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2')->render(), 'a client does not schedule');
        self::assertStringNotContainsString('data-live-choice-param="release"', (string) $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', $intranetBug->getKey())->render(), 'a release belongs to one project');

        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');
        $component->call('choose', ['choice' => 'release'])->set('releaseId', $release->getId())->call('apply');

        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame('2.5.0', $this->reloaded('MD-SPT-1')->getRelease()?->getName());
        self::assertSame('2.5.0', $this->reloaded('MD-SPT-2')->getRelease()?->getName());
    }

    public function testAManagerMovesSeveralIssuesToAnotherTrackerAtOnce(): void
    {
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('choose', ['choice' => 'move']);
        $render = (string) $component->render();
        self::assertStringContainsString('<option value="new" selected>New</option>', $render, 'the first place of the chosen workflow');

        $component->set('moveTrackerId', (string) $this->scene->evolutions->getId())->set('placeholder', 'x');
        self::assertStringContainsString('To estimate', (string) $component->render());

        $component->set('movePlaceId', 'to_estimate')->set('moveTrackerId', (string) $this->scene->evolutions->getId())->call('apply');

        self::assertSame(302, $component->response()->getStatusCode());
        foreach (['MD-SPT-1', 'MD-SPT-2'] as $key) {
            $issue = $this->reloaded($key);
            self::assertSame('Feature', $issue->getTracker()->getName(), $key);
            self::assertSame('to_estimate', $issue->getPlace(), $key);
        }
    }

    public function testEachIssueIsItsOwnCommandSoARefusalLeavesTheOthersDone(): void
    {
        $intranetBug = $this->scene->intranetBug();
        $nadia = $this->user('nadia.benali@cairn.test');
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', $intranetBug->getKey());

        $component->call('choose', ['choice' => 'assign'])->set('assigneeId', $nadia->getId())->call('apply');

        self::assertSame(302, $component->response()->getStatusCode(), 'what went through is kept');
        self::assertSame('nadia.benali@cairn.test', $this->reloaded('MD-SPT-1')->getAssignee()?->getEmail());
        self::assertNull($this->reloaded($intranetBug->getKey())->getAssignee(), 'she is not a member of the intranet');
    }

    public function testWhenEveryIssueIsRefusedThePageSaysWhy(): void
    {
        $marc = $this->user('marc.petit@cairn.test');
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('choose', ['choice' => 'assign'])->set('assigneeId', $marc->getId())->call('apply');

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertStringContainsString('No issue was updated', (string) $component->render());
        self::assertNull($this->reloaded('MD-SPT-1')->getAssignee());
    }

    public function testAnActionTheSelectionDoesNotOfferIsIgnored(): void
    {
        $component = $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('choose', ['choice' => 'assign']);
        self::assertNull($component->component()->action ?? null);

        $component->set('action', 'transition:qualify')->call('apply');
        self::assertSame(200, $component->response()->getStatusCode());
        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace(), 'the browser\'s choice is checked again');
    }

    private function selection(string $email, string ...$keys): TestLiveComponent
    {
        $ids = array_map(fn (string $key): int => (int) $this->reloaded($key)->getId(), array_values($keys));

        return $this->createLiveComponent('IssueList:Selection', ['issueIds' => $ids, 'back' => self::FROM])
            ->actingAs($this->user($email));
    }

    private function reloaded(string $key): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Issue::class)->findOneBy(['key' => $key]) ?? throw new \LogicException(\sprintf('No %s.', $key));
    }

    private function user(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new \LogicException(\sprintf('No %s.', $email));
    }
}

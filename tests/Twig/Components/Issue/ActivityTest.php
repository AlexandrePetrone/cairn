<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use App\Twig\Components\Issue\Activity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * MD-SPT-4 waits on « À recetter »: the client accepts or refuses, a
 * refusal needs a comment.
 */
final class ActivityTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        AppStory::load();
        $this->issue = self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-4') ?? self::fail('no MD-SPT-4');
    }

    public function testTheActionsAreThoseOfTheViewersRole(): void
    {
        $client = (string) $this->activity('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('Valider', $client);
        self::assertStringContainsString('Refuser', $client);
        self::assertStringNotContainsString('Commenter en privé', $client);

        $developer = (string) $this->activity('nadia.benali@cairn.test')->render();
        self::assertStringNotContainsString('data-live-transition-param="accept"', $developer, 'acceptance is for the client and the manager');
        self::assertStringContainsString('data-live-action-param="postComment"', $developer);

        $readOnly = (string) $this->activity('paul.roussel@maison-dupont.test')->render();
        self::assertStringNotContainsString('data-live-action-param="postComment"', $readOnly);
        self::assertStringNotContainsString('data-live-transition-param', $readOnly);
        self::assertStringContainsString('a créé la demande', $readOnly, 'the thread is shown to everyone');
    }

    public function testARefusalNeedsItsCommentThenRedrawsInPlace(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->call('apply', ['transition' => 'refuse']);
        self::assertStringContainsString('un commentaire est obligatoire pour refuser', (string) $component->render());
        self::assertSame('to_test', $this->reloaded()->getPlace());

        $component->set('body', 'Toujours vide en production.')->call('apply', ['transition' => 'refuse']);
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertSame('in_progress', $this->reloaded()->getPlace());
        self::assertStringContainsString('a appliqué « Refuser »', (string) $render);
        self::assertStringContainsString('Toujours vide en production.', (string) $render);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame('', $activity->body, 'the editor is emptied');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4 : « Refuser » appliquée.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testAClientCommentIsPublicWhateverTheFlag(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('body', 'Merci, je regarde demain.')->set('private', true)->call('postComment');

        self::assertStringContainsString('Merci, je regarde demain.', (string) $component->render());
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Merci, je regarde demain.', $comment?->getBody());
        self::assertFalse($comment->isPrivate());
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $component = $this->activity('nadia.benali@cairn.test');

        $component->set('body', '   ')->call('postComment');

        self::assertStringContainsString('Le commentaire est vide.', (string) $component->render());
    }

    public function testTheCommentBeingWrittenSurvivesAChangeMadeElsewhere(): void
    {
        $component = $this->activity('thomas.girard@cairn.test');
        $component->set('body', 'Brouillon en cours');

        $component->emit('issue:changed');

        self::assertStringContainsString('Brouillon en cours', (string) $component->render());
    }

    private function activity(string $email): TestLiveComponent
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $this->createLiveComponent('Issue:Activity', ['issue' => $this->issue])->actingAs($user);
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Issue\Activity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * MD-SPT-4 waits on « À recetter »: the client accepts or refuses, a
 * refusal needs a comment.
 */
final class ActivityTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->issue = $this->scene->issue('MD-SPT-4');
    }

    public function testTheBoxOffersTheActionsOfTheViewersRoleOrTheCommentAlone(): void
    {
        $client = (string) $this->activity('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('Que souhaitez-vous faire ?', $client);
        self::assertStringContainsString('value="accept"', $client);
        self::assertStringContainsString('value="refuse"', $client);
        self::assertStringContainsString('Commenter seulement', $client);
        self::assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-live-action-param="submit"/', $client, 'nothing chosen yet');
        self::assertStringNotContainsString('Commenter en privé', $client);

        $developer = (string) $this->activity('nadia.benali@cairn.test')->render();
        self::assertStringNotContainsString('value="accept"', $developer, 'acceptance is for the client and the manager');
        self::assertStringNotContainsString('Que souhaitez-vous faire ?', $developer, 'no action, no choice: the editor alone');
        self::assertStringContainsString('data-live-action-param="submit"', $developer);
        self::assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*data-live-action-param="submit"/', $developer);

        $readOnly = (string) $this->activity('paul.roussel@maison-dupont.test')->render();
        self::assertStringNotContainsString('data-live-action-param="submit"', $readOnly);
        self::assertStringNotContainsString('Valider', $readOnly, 'the actions of others are nobody\'s business');
        self::assertStringContainsString('a créé la demande', $readOnly, 'the thread is shown to everyone');
    }

    public function testEachChoiceSaysWhatItDoes(): void
    {
        $claire = 'claire.martin@maison-dupont.test';

        $new = (string) $this->activityOn('MD-SPT-1', $claire)->render();
        self::assertStringNotContainsString('name="outcome"', $new, 'qualifying is for the agency: Claire only comments');
        self::assertStringNotContainsString('Qualifier', $new);

        $toTest = (string) $this->activity($claire)->render();
        self::assertStringContainsString('La correction est acceptée', $toTest, 'the description of the configuration');
        self::assertStringContainsString('Commentaire obligatoire.', $toTest, 'under « Refuser »');
        self::assertStringContainsString('Poser une question, préciser ou signaler quelque chose.', $toTest);

        $done = (string) $this->activityOn('MD-SPT-5', $claire)->render();
        self::assertStringContainsString('value="reopen"', $done);
    }

    public function testTheAgencyIsNotHeldToTheCommentAskedOfClients(): void
    {
        $component = $this->activity('thomas.girard@cairn.test');
        self::assertStringNotContainsString('Commentaire obligatoire.', (string) $component->render());

        $component->set('outcome', 'refuse')->call('submit');

        self::assertSame('in_progress', $this->reloaded()->getPlace(), 'refused without a word');
    }

    public function testNothingIsSentBeforeAChoice(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('body', 'Merci.')->call('submit');

        self::assertStringContainsString('Choisissez ce que vous souhaitez faire.', (string) $component->render());
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['body' => 'Merci.']));
        self::assertSame('to_test', $this->reloaded()->getPlace());
    }

    public function testTheButtonTakesTheNameOfTheChoice(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('outcome', 'accept');
        self::assertMatchesRegularExpression('/<button[^>]*data-live-action-param="submit"[^>]*>\\s*Valider\\s*<\\/button>/', (string) $component->render());

        $component->set('outcome', 'comment');
        self::assertMatchesRegularExpression('/<button[^>]*data-live-action-param="submit"[^>]*>\\s*Commenter\\s*<\\/button>/', (string) $component->render());
    }

    public function testARefusalNeedsItsCommentThenRedrawsInPlace(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('outcome', 'refuse')->call('submit');
        self::assertStringContainsString('un commentaire est obligatoire pour refuser', (string) $component->render());
        self::assertSame('to_test', $this->reloaded()->getPlace());

        $component->set('body', 'Toujours vide en production.')->call('submit');
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertSame('in_progress', $this->reloaded()->getPlace());
        self::assertStringContainsString('a appliqué « Refuser »', (string) $render);
        self::assertStringContainsString('Toujours vide en production.', (string) $render);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame('', $activity->body, 'the editor is emptied');
        self::assertNull($activity->outcome, 'the next response starts from no choice');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4 : « Refuser » appliquée.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testAClientCommentIsPublicWhateverTheFlag(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('body', 'Merci, je regarde demain.')->set('private', true)->set('outcome', 'comment')->call('submit');

        self::assertStringContainsString('Merci, je regarde demain.', (string) $component->render());
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Merci, je regarde demain.', $comment?->getBody());
        self::assertFalse($comment->isPrivate());
    }

    public function testACommentIsWrittenInMarkdownAndTheEditorIsToldToReset(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('body', 'Toujours **vide**, comme #MD-SPT-2.')->set('outcome', 'comment')->call('submit');
        $render = $component->render();

        self::assertStringContainsString('Toujours <strong>vide</strong>, comme <a class="issue-reference" href="/MD/SPT/issues/2"', (string) $render);
        self::assertNotNull($component->getDispatchedBrowserEvent($render, 'editor:reset'), 'the editor, outside the morph, empties itself');
    }

    public function testFilesSentWithACommentGoUnderIt(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->call('attach', [], ['files' => [FilesTest::file('devis.pdf'), FilesTest::file('brouillon.txt')]]);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertCount(2, $activity->pending);
        self::assertStringContainsString('brouillon.txt', (string) $component->render());

        $component->call('discard', ['uuid' => $activity->pending[1]]);
        $component->set('body', 'Voici le devis.')->set('outcome', 'comment')->call('submit');
        $render = (string) $component->render();

        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Voici le devis.', $comment?->getBody());
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame([], $activity->pending, 'the composer starts afresh');
        self::assertStringContainsString('devis.pdf', $render);
        self::assertStringNotContainsString('brouillon.txt', $render, 'a discarded file stays out');
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $component = $this->activity('nadia.benali@cairn.test');

        $component->set('body', '   ')->call('submit');

        self::assertStringContainsString('Le commentaire est vide.', (string) $component->render());
    }

    public function testTheCommentBeingWrittenSurvivesAChangeMadeElsewhere(): void
    {
        $component = $this->activity('thomas.girard@cairn.test');
        $component->set('body', 'Brouillon en cours');

        $component->emit('issue:changed');

        self::assertStringContainsString('Brouillon en cours', (string) $component->render());
    }

    public function testTheFilesOfACommentAreRemovedByWhoMayEditIt(): void
    {
        $claire = $this->activity('claire.martin@maison-dupont.test');
        $claire->call('attach', [], ['files' => [FilesTest::file('devis.pdf')]]);
        $activity = $claire->component();
        self::assertInstanceOf(Activity::class, $activity);
        $remove = \sprintf('data-live-action-param="removeAttachment" data-live-uuid-param="%s"', $activity->pending[0]);
        $claire->set('body', 'Voici le devis.')->set('outcome', 'comment')->call('submit');
        self::assertStringContainsString($remove, (string) $claire->render());

        $render = (string) $this->activity('nadia.benali@cairn.test')->render();
        self::assertStringContainsString('devis.pdf', $render);
        self::assertStringNotContainsString($remove, $render, 'Nadia did not write that comment');
        self::assertStringContainsString($remove, (string) $this->activity('thomas.girard@cairn.test')->render(), 'comment.edit');
    }

    public function testACommentIsRewrittenInPlaceWithoutLosingTheDraft(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $id = $this->postedComment($component, 'Merci, c\'est bloquant.');
        $component->set('body', 'Brouillon en cours');
        self::assertStringContainsString(\sprintf('data-live-action-param="editComment" data-live-id-param="%d"', $id), (string) $component->render());

        $component->call('editComment', ['id' => $id]);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame($id, $activity->editingCommentId);
        $component->call('attach', [], ['files' => [FilesTest::file('precision.pdf')]]);
        $component->set('commentBody', 'Merci, **vraiment** bloquant.')->call('saveComment');
        $render = (string) $component->render();
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertNull($activity->error);

        self::assertSame('Merci, **vraiment** bloquant.', $this->reloadedComment($id)?->getBody());
        self::assertStringContainsString('modifié le', $render);
        self::assertStringContainsString('a modifié son commentaire', $render);
        self::assertStringContainsString('precision.pdf', $render, 'a file sent while rewriting goes with the comment');
        self::assertStringContainsString('Brouillon en cours', $render, 'the composer keeps its draft');
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertNull($activity->editingCommentId);
        self::assertSame([], $activity->editPending);
    }

    public function testACommentIsDeletedAfterConfirmationAndItsTextStaysInTheThread(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $body = 'À supprimer, envoyé par erreur.';
        $id = $this->postedComment($component, $body);

        $component->call('confirmDelete', ['id' => $id]);
        self::assertStringContainsString('Supprimer ce commentaire ?', (string) $component->render());

        $component->call('deleteComment');
        $render = $component->render();

        self::assertNull($this->reloadedComment($id));
        self::assertStringContainsString('a supprimé son commentaire', (string) $render);
        self::assertStringContainsString($body, (string) $render, 'the deleted text is still readable');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
    }

    public function testEditingControlsFollowTheRightOnTheComment(): void
    {
        $comments = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class);
        $nadiaComment = $comments->findOneBy(['issue' => $this->issue, 'author' => $this->scene->user('nadia.benali@cairn.test')]) ?? self::fail('no comment of Nadia');
        $claireComment = $comments->findOneBy(['issue' => $this->issue, 'author' => $this->scene->user('claire.martin@maison-dupont.test')]) ?? self::fail('no comment of Claire');

        $component = $this->activity('nadia.benali@cairn.test');
        $developer = (string) $component->render();
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame([(int) $nadiaComment->getId()], $activity->getEditableComments(), 'Nadia edits her own comment only');
        self::assertStringContainsString(\sprintf('data-live-action-param="editComment" data-live-id-param="%d"', $nadiaComment->getId()), $developer, 'her own');
        self::assertStringNotContainsString(\sprintf('data-live-id-param="%d"', $claireComment->getId()), $developer, 'not hers');

        $paul = (string) $this->activity('paul.roussel@maison-dupont.test')->render();
        self::assertStringNotContainsString('data-live-action-param="editComment"', $paul);
        self::assertStringNotContainsString('data-live-action-param="confirmDelete"', $paul);
    }

    /** Posts a comment from the composer and returns its id. */
    private function postedComment(TestLiveComponent $component, string $body): int
    {
        $component->set('body', $body)->set('outcome', 'comment')->call('submit');
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame($body, $comment?->getBody());

        return (int) $comment->getId();
    }

    /** The comment as the database has it; a removed one is already out of the identity map. */
    private function reloadedComment(int $id): ?Comment
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $comment = $entityManager->find(Comment::class, $id);
        if (null !== $comment) {
            $entityManager->refresh($comment);
        }

        return $comment;
    }

    private function activity(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Activity', ['issue' => $this->issue])->actingAs($this->scene->user($email));
    }

    private function activityOn(string $key, string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Activity', ['issue' => $this->scene->issue($key)])->actingAs($this->scene->user($email));
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}

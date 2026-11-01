<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Attachment;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Repository\TrackerRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * On the development dataset: the Maison Dupont shop (MD-SPT) has 18
 * issues, a public "Anomalie" tracker on the bug workflow (environment
 * required, estimate internal only) and a private "Tâche interne" one.
 */
final class CreateFormTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private Project $shop;
    private Tracker $bugs;
    private Tracker $tasks;

    protected function setUp(): void
    {
        self::bootKernel();
        AppStory::load();
        $shop = self::getContainer()->get(ProjectRepository::class)->findOneByKeys('MD', 'SPT');
        self::assertNotNull($shop);
        $this->shop = $shop;
        $trackers = self::getContainer()->get(TrackerRepository::class);
        $this->bugs = $trackers->findOneBy(['project' => $shop, 'name' => 'Anomalie']) ?? self::fail('no bug tracker');
        $this->tasks = $trackers->findOneBy(['project' => $shop, 'name' => 'Tâche interne']) ?? self::fail('no task tracker');
    }

    public function testAClientPicksAPublicTrackerAndCreatesAnIssue(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test');

        $rendered = (string) $component->render();
        self::assertStringContainsString('Anomalie', $rendered);
        self::assertStringNotContainsString('Tâche interne', $rendered, 'private trackers are not offered');
        self::assertStringNotContainsString('issue[title]', $rendered, 'no form before the choice');

        $rendered = (string) $component->set('trackerId', $this->bugs->getId())->render();
        self::assertStringContainsString('issue[fields][environment]', $rendered);
        self::assertStringNotContainsString('issue[fields][estimate]', $rendered, 'internal-only field');
        self::assertStringNotContainsString('issue[assignee]', $rendered, 'clients do not assign');
        self::assertStringNotContainsString('issue[private]', $rendered, 'clients do not hide');

        $component->submitForm(['issue' => [
            'title' => '  Le panier se vide tout seul  ',
            'description' => 'Après dix minutes, le panier est vide.',
            'fields' => ['environment' => 'prod', 'url' => 'https://shop.test/panier'],
            'criticality' => 'high',
        ]], 'save');

        $response = $component->response();
        self::assertSame(302, $response->getStatusCode(), $response->getContent() ?: '');
        self::assertSame('/MD/SPT/issues/19', $response->headers->get('Location'));

        $issue = $this->issue('MD-SPT-19');
        self::assertSame('Le panier se vide tout seul', $issue->getTitle());
        self::assertSame(Criticality::High, $issue->getCriticality());
        self::assertSame(['environment' => 'prod', 'url' => 'https://shop.test/panier'], $issue->getFieldValues());
        self::assertSame('new', $issue->getPlace());
        self::assertFalse($issue->isPrivate());
        self::assertSame('claire.martin@maison-dupont.test', $issue->getAuthor()->getEmail());
    }

    public function testFilesSentWhileWritingGoToTheNewIssue(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test');
        $component->set('trackerId', $this->bugs->getId());

        $component->call('attach', [], ['files' => [FilesTest::file('trace.log')]]);
        self::assertStringContainsString('trace.log', (string) $component->render());
        $component->submitForm(['issue' => [
            'title' => 'Le panier se vide tout seul',
            'description' => 'Voir la trace.',
            'fields' => ['environment' => 'prod'],
            'criticality' => 'normal',
        ]], 'save');

        self::assertSame(302, $component->response()->getStatusCode());
        $files = self::getContainer()->get(AttachmentRepository::class)->findByIssue($this->issue('MD-SPT-19'));
        self::assertSame(['trace.log'], array_map(static fn (Attachment $file): string => $file->getFilename(), $files));
    }

    public function testRequiredFieldsAreCheckedByTheForm(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test')->set('trackerId', $this->bugs->getId());

        // The test client lets the 422 of an invalid form through as the exception it comes from.
        try {
            $component->submitForm(['issue' => ['title' => '', 'description' => 'Sans titre ni environnement.']], 'save');
            self::fail('The form is invalid');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('issue.title: Cette valeur ne doit pas être vide.', $e->getMessage());
            self::assertStringContainsString('issue.fields.environment: Cette valeur ne doit pas être vide.', $e->getMessage());
        }
        self::assertNull(self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-19'));
    }

    public function testAnInternalUserQualifiesAtCreation(): void
    {
        $lea = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'lea.fontaine@cairn.test']);
        $component = $this->form('nadia.benali@cairn.test')->set('trackerId', $this->tasks->getId());
        $rendered = (string) $component->render();
        self::assertStringContainsString('issue[assignee]', $rendered);
        self::assertStringNotContainsString('issue[private]', $rendered, 'a private tracker leaves no choice');
        self::assertStringContainsString('issue[private]', (string) $component->set('trackerId', $this->bugs->getId())->render(), 'offered on a public tracker');

        $component->set('trackerId', $this->tasks->getId())->submitForm(['issue' => [
            'title' => 'Purger les sessions expirées',
            'description' => 'Le cron ne tourne plus.',
            'assignee' => (string) $lea?->getId(),
        ]], 'save');

        self::assertSame(302, $component->response()->getStatusCode(), $component->response()->getContent() ?: '');
        $issue = $this->issue('MD-SPT-19');
        self::assertSame('lea.fontaine@cairn.test', $issue->getAssignee()?->getEmail());
        self::assertTrue($issue->isPrivate(), 'private through its tracker');
        self::assertSame('todo', $issue->getPlace());
    }

    public function testATrackerTheViewerMayNotUseIsIgnored(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test')->set('trackerId', $this->tasks->getId());

        self::assertStringNotContainsString('issue[title]', (string) $component->render(), 'a private tracker is no choice for a client');

        $component->submitForm(['issue' => ['title' => 'Caché', 'description' => 'x']], 'save');
        self::assertSame(200, $component->response()->getStatusCode(), 'nothing happens');
        self::assertNull(self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-19'));
    }

    private function form(string $email): TestLiveComponent
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $this->createLiveComponent('Issue:CreateForm', ['project' => $this->shop])->actingAs($user);
    }

    private function issue(string $key): Issue
    {
        $issue = self::getContainer()->get(IssueRepository::class)->findOneByKey($key);
        self::assertNotNull($issue);

        return $issue;
    }
}

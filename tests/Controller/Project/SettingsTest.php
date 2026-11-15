<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Tracker;
use App\Repository\TrackerRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The configuration and the access of a project, for its manager: Thomas
 * manages Maison Dupont, Nadia develops on the shop, Claire is its client.
 * The ordering of trackers is covered by OrderTest, the grants by GrantsTest.
 */
final class SettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheManagerFindsTheConfigurationAndTheAccessInTheNavigation(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('a[href="/MD/SPT/settings"]');
        self::assertSelectorExists('a[href="/MD/SPT/access"]');

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('a[href="/MD/SPT/settings"]');
        self::assertSelectorNotExists('a[href="/MD/SPT/access"]');
    }

    public function testWhoDoesNotManageTheProjectIsRefused(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        foreach (['/MD/SPT/settings', '/MD/SPT/settings/general', '/MD/SPT/access'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheManagerCreatesATracker(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings');
        self::assertResponseRedirects(null, null, 'the page of the first tracker, the others beside it');
        $this->client->followRedirect();
        self::assertRouteSame('app_project_settings_tracker');
        self::assertSelectorTextContains('main', 'Internal task');
        self::assertSelectorNotExists('[role="dialog"]');
        self::assertSelectorExists('turbo-frame#modal', 'where the dialog loads over the page');

        $this->client->clickLink('New tracker');
        self::assertRouteSame('app_project_settings_tracker_new');
        self::assertSelectorExists('turbo-frame#modal form[name="new_tracker"][action="/MD/SPT/settings/trackers/new"] [role="dialog"]', 'loaded over the page, the form is still sent to the dialog, under a name of its own');

        $this->client->submitForm('Create the tracker', ['new_tracker[name]' => ''], 'POST');
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Create the tracker', ['new_tracker[name]' => '   ', 'new_tracker[workflowId]' => 'task'], 'POST');
        self::assertResponseStatusCodeSame(422, 'a blank name is refused by the form, not by the bus');

        $this->client->submitForm('Create the tracker', [
            'new_tracker[name]' => 'Support',
            'new_tracker[description]' => 'A question about the site.',
            'new_tracker[workflowId]' => 'task',
        ]);

        $support = self::getContainer()->get(TrackerRepository::class)->findOneBy(['name' => 'Support']);
        self::assertInstanceOf(Tracker::class, $support);
        self::assertSame('task', $support->getWorkflowId());
        self::assertSame(3, $support->getPosition());
        self::assertResponseRedirects(\sprintf('/MD/SPT/settings/trackers/%d', $support->getId()));
    }

    public function testTheDialogCreatingATrackerIsAnsweredAloneToItsFrame(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $frame = ['HTTP_TURBO_FRAME' => 'modal'];

        $this->client->request('GET', '/MD/SPT/settings/trackers/new', [], [], $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal form[name="new_tracker"]');
        self::assertSelectorNotExists('nav', 'nothing of the page behind the dialog');

        $this->client->submitForm('Create the tracker', ['new_tracker[name]' => ''], 'POST', $frame);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#modal [role="dialog"]', 'refused, the dialog alone is drawn again');
        self::assertSelectorNotExists('nav');

        $this->client->request('GET', \sprintf('/MD/SPT/settings/trackers/%d', $this->scene->evolutions->getId()), [], [], $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('turbo-frame#modal *', 'where a creation leads, the frame is answered empty');
        self::assertSelectorNotExists('nav');
    }

    public function testTheManagerDeactivatesATrackerWhoseWorkflowIsShown(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $url = \sprintf('/MD/SPT/settings/trackers/%d', $this->scene->evolutions->getId());

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="workflow-diagram"]');

        $this->client->submitForm('Save', ['tracker[name]' => 'Features', 'tracker[active]' => false]);
        self::assertResponseRedirects($url);

        $evolutions = self::getContainer()->get(TrackerRepository::class)->find($this->scene->evolutions->getId());
        self::assertInstanceOf(Tracker::class, $evolutions);
        self::assertSame('Features', $evolutions->getName());
        self::assertFalse($evolutions->isActive());

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists(\sprintf('nav a[href="/MD/SPT/issues/tracker/%d"]', $evolutions->getId()), 'a deactivated tracker leaves the menu');
    }

    public function testATrackerOfAnotherProjectIsNotFound(): void
    {
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/INT/settings/trackers/%d', $this->scene->bugs->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheManagerRenamesTheProjectAndDescribesIt(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/general');

        $this->client->submitForm('Save', [
            'project_settings[name]' => 'Web shop',
            'project_settings[description]' => 'The public site and the back office.',
        ]);

        self::assertResponseRedirects('/MD/SPT/settings/general');
        $this->client->followRedirect();
        self::assertInputValueSame('project_settings[name]', 'Web shop');
        self::assertSelectorTextContains('textarea', 'The public site and the back office.');
    }

    public function testTheAccessPageShowsTheReferentsTheInheritedRolesThenTheDirectOnes(): void
    {
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/access');

        self::assertResponseIsSuccessful();
        $sections = $this->client->getCrawler()->filter('main section');
        self::assertStringContainsString('Referents', $sections->eq(0)->text(), 'who answers for each role comes first');
        self::assertStringContainsString('Claire Martin', $sections->eq(1)->text(), 'inherited from Maison Dupont');
        self::assertStringContainsString('Nadia Benali', $sections->eq(2)->text(), 'held on the shop');
        self::assertStringNotContainsString('Claire Martin', $sections->eq(2)->filter('ul')->text());
        self::assertSelectorNotExists('[role="dialog"]');

        $this->client->clickLink('Add someone');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="dialog"]', 'Add someone to Online shop');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

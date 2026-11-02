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
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Tâche interne');

        $this->client->submitForm('Créer la catégorie', ['tracker[name]' => ''], 'POST');
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Créer la catégorie', ['tracker[name]' => '   ', 'tracker[workflowId]' => 'task'], 'POST');
        self::assertResponseStatusCodeSame(422, 'a blank name is refused by the form, not by the bus');

        $this->client->submitForm('Créer la catégorie', [
            'tracker[name]' => 'Support',
            'tracker[description]' => 'Une question sur le site.',
            'tracker[workflowId]' => 'task',
        ]);

        $support = self::getContainer()->get(TrackerRepository::class)->findOneBy(['name' => 'Support']);
        self::assertInstanceOf(Tracker::class, $support);
        self::assertSame('task', $support->getWorkflowId());
        self::assertSame(3, $support->getPosition());
        self::assertResponseRedirects(\sprintf('/MD/SPT/settings/trackers/%d', $support->getId()));
    }

    public function testTheManagerDeactivatesATrackerWhoseWorkflowIsShown(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $url = \sprintf('/MD/SPT/settings/trackers/%d', $this->scene->evolutions->getId());

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="workflow-diagram"]');

        $this->client->submitForm('Enregistrer', ['tracker[name]' => 'Évolutions', 'tracker[active]' => false]);
        self::assertResponseRedirects($url);

        $evolutions = self::getContainer()->get(TrackerRepository::class)->find($this->scene->evolutions->getId());
        self::assertInstanceOf(Tracker::class, $evolutions);
        self::assertSame('Évolutions', $evolutions->getName());
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

        $this->client->submitForm('Enregistrer', [
            'project_settings[name]' => 'Boutique en ligne',
            'project_settings[description]' => 'Le site public et le back-office.',
        ]);

        self::assertResponseRedirects('/MD/SPT/settings/general');
        $this->client->followRedirect();
        self::assertInputValueSame('project_settings[name]', 'Boutique en ligne');
        self::assertSelectorTextContains('textarea', 'Le site public et le back-office.');
    }

    public function testTheAccessPageShowsTheInheritedRolesThenTheDirectOnes(): void
    {
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/access');

        self::assertResponseIsSuccessful();
        $sections = $this->client->getCrawler()->filter('main section');
        self::assertStringContainsString('Claire Martin', $sections->eq(0)->text(), 'inherited from Maison Dupont');
        self::assertStringContainsString('Nadia Benali', $sections->eq(1)->text(), 'held on the shop');
        self::assertStringNotContainsString('Claire Martin', $sections->eq(1)->filter('ul')->text());
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

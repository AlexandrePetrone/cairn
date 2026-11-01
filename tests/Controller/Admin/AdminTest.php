<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The administration pages. In the scene, Julie administers the instance,
 * Thomas manages Maison Dupont (MD: SPT, INT), Travelia (TR) holds the
 * archived RES project.
 */
final class AdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testOnlyAdministratorsEnter(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        foreach (['/admin/projects', '/admin/workspaces/MD', '/admin/users'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('header a[href="/admin"]', 'no way in for a project manager');
    }

    public function testTheProjectsAreListedByWorkspaceAndFiltered(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('header a[href="/admin"]');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/projects');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Maison Dupont');
        self::assertSelectorTextContains('main', 'Site de réservation');
        self::assertSelectorTextContains('main', 'Archivé');

        $this->client->request('GET', '/admin/projects?state[]=active');
        self::assertSelectorTextNotContains('main', 'Site de réservation');

        $this->client->request('GET', '/admin/projects?state[]=nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAProjectIsCreatedInAWorkspace(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/projects/new?workspace=MD');

        $this->client->submitForm('Créer', ['project[name]' => 'Application', 'project[key]' => 'spt']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Un projet de Maison Dupont utilise déjà le code SPT.');

        $this->client->submitForm('Créer', ['project[name]' => 'Application', 'project[key]' => 'app']);
        self::assertResponseRedirects('/admin/workspaces/MD');
        self::assertNotNull($this->entity(Project::class, ['key' => 'APP']));
    }

    public function testAWorkspaceIsCreatedRenamedAndArchived(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/workspaces/new');
        $this->client->submitForm('Créer', ['workspace[name]' => 'Atelier Morel', 'workspace[key]' => 'am']);
        self::assertResponseRedirects('/admin/workspaces/AM');

        $this->client->request('GET', '/admin/workspaces/MD/settings');
        $this->client->submitForm('Enregistrer', ['workspace[name]' => 'Maison Dupont & fils', 'workspace[archived]' => true]);
        self::assertResponseRedirects('/admin/workspaces/MD/settings');

        $workspace = $this->entity(Workspace::class, ['key' => 'MD']) ?? self::fail('MD vanished');
        self::assertSame('Maison Dupont & fils', $workspace->getName());
        self::assertTrue($workspace->isArchived());

        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('header a[href="/MD/SPT/issues/new"]', 'read-only');
    }

    public function testAProjectIsArchivedFromTheList(): void
    {
        $this->signInAsAdmin();
        $crawler = $this->client->request('GET', '/admin/workspaces/MD');

        $this->client->submit($crawler->filter('form[action="/admin/projects/MD/INT"]')->form());
        self::assertResponseRedirects('/admin/workspaces/MD');
        self::assertTrue($this->entity(Project::class, ['key' => 'INT'])?->isArchived());
    }

    public function testAnAccountIsCreatedThenEdited(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Créer', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[type]' => 'external',
            'user[password]' => 'court',
        ]);
        self::assertResponseStatusCodeSame(422, 'password too short');

        $this->client->submitForm('Créer', ['user[password]' => 'un mot de passe']);
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']);
        self::assertNotNull($ines);
        self::assertFalse($ines->isInternal());
        self::assertResponseRedirects('/admin/users/'.$ines->getId());

        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer', ['user[lastName]' => 'Garnier-Roy', 'user[type]' => 'internal', 'user[password]' => '']);
        self::assertResponseRedirects();
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']) ?? self::fail('Inès vanished');
        self::assertSame('Inès Garnier-Roy', $ines->getFullName());
        self::assertTrue($ines->isInternal(), 'one form, one save');
        self::assertNotNull($ines->getPassword(), 'an empty password field keeps the password');
    }

    public function testMakingAManagerExternalIsRefusedWithTheReason(): void
    {
        $this->signInAsAdmin();
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $crawler = $this->client->request('GET', '/admin/users/'.$thomas->getId());

        $form = $crawler->filter('form[name="user"]')->form(['user[type]' => 'external']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'Thomas Girard a des rôles réservés aux internes (Maison Dupont)');
    }

    public function testTheLastAdministratorIsNotDeactivated(): void
    {
        $this->signInAsAdmin();
        $julie = $this->scene->user('julie.admin@cairn.test');
        $this->client->request('GET', '/admin/users/'.$julie->getId());

        $this->client->submitForm('Enregistrer', ['user[active]' => false]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'Il doit rester au moins un administrateur actif.');
    }

    public function testTheUsersAreFiltered(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/users?type[]=external&q=martin');

        self::assertSelectorTextContains('tbody', 'Claire Martin');
        self::assertSelectorTextNotContains('tbody', 'Thomas Girard');
    }

    private function signInAsAdmin(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return T|null
     */
    private function entity(string $class, array $criteria): ?object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository($class)->findOneBy($criteria);
    }
}

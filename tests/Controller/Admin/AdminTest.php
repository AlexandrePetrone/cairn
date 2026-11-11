<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The administration pages. In the scene, Julie administers the instance,
 * Thomas manages Maison Dupont (MD: SPT, INT), Travelia (TR) holds the
 * archived RES project.
 */
final class AdminTest extends WebTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;

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
        self::assertSelectorTextContains('main', 'Booking site');
        self::assertSelectorTextContains('main', 'Archived');

        $this->client->request('GET', '/admin/projects?state[]=active');
        self::assertSelectorTextNotContains('main', 'Booking site');

        $this->client->request('GET', '/admin/projects?state[]=nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAProjectIsCreatedInAWorkspace(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/projects/new?workspace=MD');

        $this->client->submitForm('Create', ['project[name]' => 'Application', 'project[key]' => 'spt']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A project of Maison Dupont already uses the key SPT.');

        $this->client->submitForm('Create', ['project[name]' => 'Application', 'project[key]' => 'app']);
        self::assertResponseRedirects('/admin/workspaces/MD');
        self::assertNotNull($this->entity(Project::class, ['key' => 'APP']));
    }

    public function testAWorkspaceIsCreatedRenamedAndArchived(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/workspaces/new');
        $this->client->submitForm('Create', ['workspace[name]' => 'Atelier Morel', 'workspace[key]' => 'am']);
        self::assertResponseRedirects('/admin/workspaces/AM');

        $this->client->request('GET', '/admin/workspaces/MD/settings');
        $this->client->submitForm('Save', ['workspace[name]' => 'Maison Dupont & fils', 'workspace[archived]' => true]);
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

    public function testAnAccountIsCreatedWithoutAPasswordAndInvitedThenEdited(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        self::assertSelectorNotExists('input[type="password"]', 'nobody types a password for someone else');

        $this->client->submitForm('Create and invite', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[company]' => 'Natura',
            'user[type]' => 'external',
        ]);
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']);
        self::assertNotNull($ines);
        self::assertFalse($ines->isInternal());
        self::assertFalse($ines->hasPassword());
        self::assertSame('Natura', $ines->getCompany());
        self::assertResponseRedirects('/admin/users/'.$ines->getId());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'ines@natura.test');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invited');
        self::assertSelectorExists('form[action="/admin/users/'.$ines->getId().'/invite"]');

        $this->client->submitForm('Save', ['user[lastName]' => 'Garnier-Roy', 'user[type]' => 'internal', 'user[company]' => '']);
        self::assertResponseRedirects();
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']) ?? self::fail('Inès vanished');
        self::assertSame('Inès Garnier-Roy', $ines->getFullName());
        self::assertTrue($ines->isInternal(), 'one form, one save');
        self::assertNull($ines->getCompany(), 'an emptied company is cleared');
    }

    public function testAnInvitationIsFollowedToChooseThePassword(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create and invite', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[type]' => 'external',
        ]);
        $link = self::passwordLink();

        $this->client->request('GET', '/logout');
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/password/set', message: 'the token leaves the address');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Choose your password');
        self::assertSelectorTextContains('main', 'ines@natura.test');

        $this->client->submitForm('Set my password', ['password_set[password][first]' => 'a long password', 'password_set[password][second]' => 'a long password']);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->client->submitForm('Sign in', ['email' => 'ines@natura.test', 'password' => 'a long password']);
        self::assertResponseRedirects('/');
    }

    public function testAnInvitationIsResent(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create and invite', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[type]' => 'external',
        ]);
        $firstLink = self::passwordLink();
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']) ?? self::fail('no account');

        $this->client->followRedirect();
        $this->client->submitForm('Resend the invitation');
        self::assertResponseRedirects('/admin/users/'.$ines->getId());
        self::assertEmailCount(1);
        $secondLink = self::passwordLink();
        self::assertNotSame($firstLink, $secondLink);

        $thomas = $this->entity(User::class, ['email' => 'thomas.girard@cairn.test']) ?? self::fail('no Thomas');
        $thomas->setPassword('hashed');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('GET', '/admin/users/'.$thomas->getId());
        self::assertSelectorNotExists('form[action$="/invite"]', 'an account with a password is not invited again');

        $visitor = $this->client;
        $visitor->request('GET', '/logout');
        $visitor->request('GET', $firstLink);
        $visitor->followRedirect();
        self::assertSelectorTextContains('h1', 'This link cannot be used', 'the new link replaces the old one');
        $visitor->request('GET', $secondLink);
        $visitor->followRedirect();
        self::assertSelectorTextContains('h1', 'Choose your password');
    }

    public function testMakingAManagerExternalIsRefusedWithTheReason(): void
    {
        $this->signInAsAdmin();
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $crawler = $this->client->request('GET', '/admin/users/'.$thomas->getId());

        $form = $crawler->filter('form[name="user"]')->form(['user[type]' => 'external']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'Thomas Girard holds roles for internal users only (Maison Dupont)');
    }

    public function testTheLastAdministratorIsNotDeactivated(): void
    {
        $this->signInAsAdmin();
        $julie = $this->scene->user('julie.admin@cairn.test');
        $this->client->request('GET', '/admin/users/'.$julie->getId());

        $this->client->submitForm('Save', ['user[active]' => false]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'At least one active administrator must remain.');
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

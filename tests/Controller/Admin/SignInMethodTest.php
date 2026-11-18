<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use App\Tests\Security\SingleSignOnEnvironment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The accounts of the administration on an instance that has an identity
 * provider: an administrator chooses how each account signs in. Without
 * a provider the pages are those of AdminTest, which show none of it.
 */
final class SignInMethodTest extends WebTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;
    use SingleSignOnEnvironment;

    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->configureSso();
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
    }

    public function testAnAccountCreatedForTheProviderIsNotInvited(): void
    {
        $this->client->request('GET', '/admin/users/new');
        self::assertSelectorTextContains('form', 'Acme ID');
        $this->client->submitForm('Create the account', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@cairn.test',
            'user[type]' => 'internal',
            'user[authMethod]' => 'sso',
        ]);

        $ines = $this->account('ines@cairn.test');
        self::assertResponseRedirects('/admin/users/'.$ines->getId());
        self::assertTrue($ines->usesSso());
        self::assertEmailCount(0);

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'has not signed in with Acme ID yet');
        self::assertSelectorNotExists('form[action$="/invite"]', 'no invitation to send again');
    }

    public function testAnAccountCreatedWithAPasswordIsInvitedAsBefore(): void
    {
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create the account', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[type]' => 'external',
        ]);

        self::assertFalse($this->account('ines@natura.test')->usesSso());
        self::assertEmailCount(1);
    }

    public function testAnAccountIsTakenToTheProviderAndBack(): void
    {
        $claire = $this->scene->user('claire.martin@maison-dupont.test');
        $url = '/admin/users/'.$claire->getId();

        $this->client->request('GET', $url);
        $this->client->submitForm('Save', ['user[authMethod]' => 'sso']);
        self::assertResponseRedirects($url);
        $claire = $this->account('claire.martin@maison-dupont.test');
        self::assertTrue($claire->usesSso());
        self::assertFalse($claire->hasPassword());

        $this->client->request('GET', $url);
        $this->client->submitForm('Save', ['user[authMethod]' => 'password']);
        $claire = $this->account('claire.martin@maison-dupont.test');
        self::assertTrue($claire->awaitsPassword());
        $this->client->followRedirect();
        self::assertSelectorExists('form[action$="/invite"]', 'to be invited, since it has no password any more');
    }

    private function account(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? self::fail('no account');
    }
}

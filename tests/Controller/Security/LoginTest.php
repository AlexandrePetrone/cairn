<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class LoginTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => 'claire@client.test', 'firstName' => 'Claire', 'lastName' => 'Martin']);
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testAUserSignsInWithEmailAndPassword(): void
    {
        $this->signIn('Claire@Client.test', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Connecté en tant que Claire Martin');
    }

    public function testAWrongPasswordKeepsTheEmailAndShowsAnError(): void
    {
        $this->signIn('claire@client.test', 'wrong');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        self::assertInputValueSame('email', 'claire@client.test');
    }

    public function testADeactivatedAccountCannotSignIn(): void
    {
        UserFactory::createOne(['email' => 'paul@client.test', 'active' => false]);

        $this->signIn('paul@client.test', UserFactory::DEFAULT_PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'Ce compte est désactivé.');
    }

    public function testRememberMeSetsALongLivedCookie(): void
    {
        $this->signIn('claire@client.test', UserFactory::DEFAULT_PASSWORD, rememberMe: true);

        self::assertBrowserHasCookie('REMEMBERME');
    }

    public function testLoggingOutEndsTheSession(): void
    {
        $this->signIn('claire@client.test', UserFactory::DEFAULT_PASSWORD);
        $this->client->followRedirect();

        $this->client->clickLink('Se déconnecter');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testASignedInUserIsSentHomeFromTheLoginPage(): void
    {
        $this->signIn('claire@client.test', UserFactory::DEFAULT_PASSWORD);

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    private function signIn(string $email, string $password, bool $rememberMe = false): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $values = ['email' => $email, 'password' => $password];
        if ($rememberMe) {
            $values['_remember_me'] = '1';
        }
        $this->client->submit($crawler->selectButton('Se connecter')->form($values));
    }
}

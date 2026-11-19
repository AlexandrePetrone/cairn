<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginTest extends WebTestCase
{
    use KernelHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The home page, where a sign-in lands, reads the configuration.
        self::applyConfiguration();
        // Login throttling counts failures in a cache that outlives a test: start each one clean.
        self::getContainer()->get('cache.rate_limiter')->clear();
        UserFactory::createOne(['email' => 'claire@client.test', 'firstName' => 'Claire', 'lastName' => 'Martin']);
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testAPageIsNeverFramedNorSniffed(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer, strict-origin-when-cross-origin');
    }

    public function testThePageIsInTheLanguageOfTheInstance(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="en"]', 'CAIRN_LOCALE is English by default');
        self::assertSelectorExists('a[href="/password/forgot"]', 'a way out of a forgotten password');
    }

    public function testWithoutAnIdentityProviderNothingOffersOne(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(0, $crawler->filter('a[href="/login/sso"]'));
        $this->client->request('GET', '/login/sso');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/login/sso/callback');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAUserSignsInWithEmailAndPassword(): void
    {
        $this->signIn('Claire@Client.test', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hello Claire');
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

        self::assertSelectorTextContains('[role="alert"]', 'This account is disabled.');
    }

    public function testADeactivatedAccountLosesItsOpenSession(): void
    {
        $this->signIn('claire@client.test', UserFactory::DEFAULT_PASSWORD);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $claire = $entityManager->getRepository(User::class)->findOneBy(['email' => 'claire@client.test']) ?? self::fail('no user');
        $claire->deactivate();
        $entityManager->flush();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->signIn('claire@client.test', 'wrong');
        }

        $this->signIn('claire@client.test', UserFactory::DEFAULT_PASSWORD);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Too many failed login attempts');
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

        $this->client->clickLink('Log out');
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
        $this->client->submit($crawler->selectButton('Sign in')->form($values));
    }
}

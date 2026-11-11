<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Forgot password?" and the page that sets a password from a link.
 * Claire has an account with the default password.
 */
final class PasswordTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use KernelHelpers;
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::applyConfiguration();
        self::getContainer()->get('cache.rate_limiter')->clear();
        UserFactory::createOne(['email' => 'claire@client.test', 'firstName' => 'Claire', 'lastName' => 'Martin']);
    }

    public function testTheLoginPageOffersToResetThePassword(): void
    {
        $this->client->request('GET', '/login');

        $this->client->clickLink('Forgot password?');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Forgot password?');
    }

    public function testAForgottenPasswordIsResetFromTheMailedLink(): void
    {
        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send me a link', ['password_request[email]' => 'Claire@Client.test']);

        self::assertResponseRedirects('/password/forgot/sent');
        self::assertEmailCount(1);
        $link = self::passwordLink();

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/password/set');
        $this->client->followRedirect();
        $this->client->submitForm('Set my password', ['password_set[password][first]' => 'a brand new one', 'password_set[password][second]' => 'a brand new one']);
        self::assertResponseRedirects('/login');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Your password is set.');
        $this->client->submitForm('Sign in', ['email' => 'claire@client.test', 'password' => 'a brand new one']);
        self::assertResponseRedirects('/');
    }

    public function testNothingTellsWhetherAnAccountExists(): void
    {
        UserFactory::createOne(['email' => 'paul@client.test', 'active' => false]);

        foreach (['nobody@client.test', 'paul@client.test'] as $email) {
            $this->client->request('GET', '/password/forgot');
            $this->client->submitForm('Send me a link', ['password_request[email]' => $email]);
            self::assertResponseRedirects('/password/forgot/sent', message: $email);
            $this->client->followRedirect();
            self::assertSelectorTextContains('main', 'If an account uses this address');
        }
        self::assertEmailCount(0);

        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send me a link', ['password_request[email]' => 'claire@client.test']);
        self::assertEmailCount(1);
        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send me a link', ['password_request[email]' => 'claire@client.test']);
        self::assertResponseRedirects('/password/forgot/sent');
        self::assertEmailCount(0, message: 'a second request within the throttle sends nothing, says nothing');
    }

    public function testAnInvalidAddressIsRefusedOnThePage(): void
    {
        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send me a link', ['password_request[email]' => 'not-an-email']);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testAnExpiredLinkIsRefusedAndOffersANewOne(): void
    {
        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send me a link', ['password_request[email]' => 'claire@client.test']);
        $link = self::passwordLink();

        self::mockTime('+2 hours');

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'This link cannot be used');
        self::assertSelectorTextContains('[role="alert"]', 'expired');
        self::assertSelectorExists('a[href="/password/forgot"]');

        $claire = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'claire@client.test']) ?? self::fail('no user');
        self::assertTrue(self::getContainer()->get('security.user_password_hasher')->isPasswordValid($claire, UserFactory::DEFAULT_PASSWORD), 'unchanged');
    }

    public function testAForgedOrMissingLinkIsRefused(): void
    {
        $this->client->request('GET', '/password/set/not-a-token-at-all-0123456789abcdefghij');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'This link cannot be used');

        $this->client->request('GET', '/password/set');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'This link cannot be used');
    }

    public function testASignedInUserIsSentHome(): void
    {
        $claire = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'claire@client.test']) ?? self::fail('no user');
        $this->client->loginUser($claire);

        $this->client->request('GET', '/password/forgot');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/password/set/sometoken');
        self::assertResponseRedirects('/', message: 'a link is for the account it was sent to, never for an open session');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\KernelHelpers;
use App\Tests\Security\SingleSignOnProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Signing in through the identity provider of the instance, from the
 * button of the sign-in page to the home page, the provider being the one
 * of SingleSignOnProvider.
 */
final class SingleSignOnTest extends WebTestCase
{
    use Factories;
    use KernelHelpers;
    use SingleSignOnProvider;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->configureSso();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::applyConfiguration();
        $this->mockProvider();
        UserFactory::new()->sso()->create(['email' => 'claire@client.test', 'firstName' => 'Claire', 'lastName' => 'Martin']);
    }

    public function testTheSignInPageOffersTheProvider(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame('Sign in with Acme ID', trim($crawler->filter('a[href="/login/sso"]')->text()));
    }

    public function testTheFirstSignInFindsTheAccountByItsAddressAndKeepsTheSubject(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'Claire@Client.test', 'email_verified' => true]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hello Claire');
        self::assertSame('acme-42', $this->account('claire@client.test')->getOidcSubject());
    }

    public function testOnceBoundTheSubjectAloneFindsTheAccount(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'claire@client.test']);
        $this->client->request('GET', '/logout');

        // The address changed at the provider, and now is the one of somebody else here.
        UserFactory::new()->sso()->create(['email' => 'paul@client.test', 'firstName' => 'Paul']);
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'paul@client.test']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hello Claire');
        self::assertNull($this->account('paul@client.test')->getOidcSubject());
    }

    public function testAnAccountBoundToSomeoneIsNotGivenToAnotherSubject(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'claire@client.test']);
        $this->client->request('GET', '/logout');

        $this->signInThroughProvider(['sub' => 'acme-99', 'email' => 'claire@client.test']);

        $this->assertRefused();
    }

    public function testNobodyIsCreated(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-7', 'email' => 'stranger@client.test']);

        $this->assertRefused();
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'stranger@client.test']));
    }

    public function testAnAccountWithAPasswordIsNotOpenedByTheProvider(): void
    {
        UserFactory::createOne(['email' => 'marc@client.test']);

        $this->signInThroughProvider(['sub' => 'acme-8', 'email' => 'marc@client.test']);

        $this->assertRefused();
        self::assertNull($this->account('marc@client.test')->getOidcSubject());
    }

    public function testAnAddressTheProviderDidNotVerifyFindsNobody(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'claire@client.test', 'email_verified' => false]);

        $this->assertRefused();
        self::assertNull($this->account('claire@client.test')->getOidcSubject());
    }

    public function testAnAddressTheProviderSaysNothingOfFindsNobody(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'claire@client.test', 'email_verified' => null]);

        $this->assertRefused();
        self::assertNull($this->account('claire@client.test')->getOidcSubject());
    }

    public function testADeactivatedAccountIsRefused(): void
    {
        $claire = $this->account('claire@client.test');
        $claire->deactivate();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'claire@client.test']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'This account is disabled.');
    }

    public function testTheSecurityPageOfTheAccountHasNoPasswordToChange(): void
    {
        $this->signInThroughProvider(['sub' => 'acme-42', 'email' => 'claire@client.test']);

        $this->client->request('GET', '/account/security');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'You sign in with Acme ID');
        self::assertSelectorNotExists('input[type="password"]');
    }

    public function testAnAccountOfTheProviderHasNoPasswordToSignInWith(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'claire@client.test', 'password' => UserFactory::DEFAULT_PASSWORD]);

        self::assertResponseRedirects('/login');
    }

    private function assertRefused(): void
    {
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'No account here signs in that way');
    }

    private function account(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new \LogicException('No such account.');
    }
}

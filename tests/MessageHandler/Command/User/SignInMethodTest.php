<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\User;

use App\Entity\Enum\AuthMethod;
use App\Entity\PasswordToken;
use App\Entity\User;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\InviteUser;
use App\Message\Command\User\RequestPasswordReset;
use App\Message\Command\User\ResetPassword;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Security\PasswordLinkException;
use App\Security\PasswordTokens;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use App\Tests\Security\SingleSignOnEnvironment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * How an account signs in, on an instance that has an identity provider:
 * what CreateUser and UpdateUser make of `authMethod`, and what the
 * password commands answer to an account the provider signs in.
 */
final class SignInMethodTest extends KernelTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;
    use SingleSignOnEnvironment;

    private CommandBus $commands;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        $this->configureSso();
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAnAccountIsCreatedForTheProviderWithoutAPassword(): void
    {
        $user = $this->commands->dispatch(new CreateUser('ines@agency.test', 'Inès', 'Garnier', internal: true, authMethod: AuthMethod::Sso));

        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->usesSso());
        self::assertFalse($user->hasPassword());
        self::assertFalse($user->awaitsPassword(), 'nothing to wait for: it has no password to choose');
        self::assertNull($user->getOidcSubject(), 'known at the first sign-in');

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new CreateUser('yann@agency.test', 'Yann', 'Leroy', plainPassword: 'correct horse', authMethod: AuthMethod::Sso));
    }

    public function testOnlyTheAdministrationChoosesTheProvider(): void
    {
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($thomas, $this->fixtures->workspace, 'project_manager');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new CreateUser('paul@client.test', 'Paul', 'Roussel', actorId: $thomas->getId(), projectId: (int) $this->fixtures->shop->getId(), authMethod: AuthMethod::Sso));
    }

    public function testNobodyChoosesHowTheySignInThemselves(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), authMethod: AuthMethod::Sso, actorId: $claire->getId()));
    }

    public function testGoingToTheProviderTakesThePasswordAndTheLinksAway(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->setPassword('hashed');
        self::getContainer()->get(PasswordTokens::class)->issueReset($claire);

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), authMethod: AuthMethod::Sso));

        self::assertTrue($claire->usesSso());
        self::assertFalse($claire->hasPassword());
        self::assertCount(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordToken::class)->findBy(['user' => $claire]));
    }

    public function testGoingBackToAPasswordForgetsTheSubjectAndWaitsForAnInvitation(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->signInWithSso();
        $claire->bindSubject('acme-42');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), authMethod: AuthMethod::Password));

        self::assertFalse($claire->usesSso());
        self::assertNull($claire->getOidcSubject());
        self::assertTrue($claire->awaitsPassword());

        $this->commands->dispatch(new InviteUser((int) $claire->getId()));
        self::assertEmailCount(1);
    }

    public function testTheSameMethodChangesNothing(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->signInWithSso();
        $claire->bindSubject('acme-42');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), firstName: 'Clara', authMethod: AuthMethod::Sso));

        self::assertSame('acme-42', $claire->getOidcSubject());
        self::assertSame('Clara', $claire->getFirstName());
    }

    public function testAnAccountOfTheProviderGetsNoPasswordLink(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->signInWithSso();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        try {
            $this->commands->dispatch(new InviteUser((int) $claire->getId()));
            self::fail('An account of the provider is not invited.');
        } catch (UserException $e) {
            self::assertStringContainsString('identity provider', $e->getMessage());
        }

        $this->commands->dispatch(new RequestPasswordReset('claire@client.test'));

        self::assertEmailCount(0, message: 'refused, or quietly nothing');
    }

    public function testALinkStillOutIsWorthNothingOnceTheProviderSignsIn(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $token = self::getContainer()->get(PasswordTokens::class)->issueInvitation($claire);
        // As a row written by hand would be: the command itself drops the links.
        $claire->signInWithSso();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        try {
            $this->commands->dispatch(new ResetPassword($token, 'correct horse'));
            self::fail('The link gives no password to an account of the provider.');
        } catch (PasswordLinkException) {
        }
        self::assertFalse($claire->hasPassword());
    }
}

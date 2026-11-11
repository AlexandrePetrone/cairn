<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Enum\ApiTokenScope;
use App\Message\Command\ApiToken\RevokeApiToken;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AuthenticationTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    public function testATokenSpeaksForItsUser(): void
    {
        $me = $this->api('GET', '/api/me', $this->tokenFor(self::DEVELOPER, ApiTokenScope::Read)->secret);

        self::assertResponseIsSuccessful();
        self::assertSame('Nadia Benali', $me['name']);
        self::assertSame(self::DEVELOPER, $me['email']);
        self::assertTrue($me['internal']);
        self::assertArrayNotHasKey('company', $me, 'the agency has none');
        self::assertSame('read', $me['scope']);
    }

    public function testWithoutATokenNothingAnswers(): void
    {
        $this->api('GET', '/api/me', null);
        self::assertResponseStatusCodeSame(401);

        $this->api('GET', '/api/me', 'cairn_'.str_repeat('0', 64));
        self::assertResponseStatusCodeSame(401);

        $this->api('GET', '/api/me', 'not-a-token');
        self::assertResponseStatusCodeSame(401);
    }

    public function testTheSessionOfThePagesDoesNotOpenTheApi(): void
    {
        $this->client->loginUser($this->scene->user(self::DEVELOPER));
        $this->client->request('GET', '/projects');
        self::assertResponseIsSuccessful();

        $this->api('GET', '/api/me', null);

        self::assertResponseStatusCodeSame(401);
    }

    public function testARevokedTokenIsRefused(): void
    {
        $new = $this->tokenFor(self::DEVELOPER);
        self::getContainer()->get(CommandBus::class)->dispatch(new RevokeApiToken((int) $new->token->getUser()->getId(), (int) $new->token->getId()));

        $this->api('GET', '/api/me', $new->secret);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $new = $this->tokenFor(self::DEVELOPER, expiresAt: new \DateTimeImmutable('2026-03-04 00:00:00'));

        $this->api('GET', '/api/me', $new->secret);
        self::assertResponseIsSuccessful();

        self::mockTime('2026-03-04 00:00:01');
        $this->api('GET', '/api/me', $new->secret);
        self::assertResponseStatusCodeSame(401);
    }

    public function testATokenFollowsItsAccount(): void
    {
        $new = $this->tokenFor(self::DEVELOPER);
        $userId = (int) $new->token->getUser()->getId();
        $commands = self::getContainer()->get(CommandBus::class);

        $commands->dispatch(new UpdateUser($userId, active: false));
        $this->api('GET', '/api/me', $new->secret);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAReadTokenDoesNotWrite(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER, ApiTokenScope::Read)->secret;

        $this->api('POST', '/api/issues/MD-SPT-1/comments', $secret, ['body' => 'Looking into it.']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheUseOfATokenIsNoted(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $new = $this->tokenFor(self::DEVELOPER);
        self::assertNull($new->token->getLastUsedAt());

        $this->api('GET', '/api/me', $new->secret);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->refresh($new->token);

        self::assertEquals(new \DateTimeImmutable('2026-03-03 10:00:00'), $new->token->getLastUsedAt());
    }

    public function testTheDocumentationIsReadSignedInByInternalUsers(): void
    {
        $this->client->request('GET', '/api/docs');
        self::assertResponseRedirects('/login');

        // A token is for the API, not for the pages.
        $this->client->request('GET', '/api/docs', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor(self::DEVELOPER)->secret]);
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->scene->user(self::CLIENT));
        $this->client->request('GET', '/api/docs');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->scene->user(self::DEVELOPER));
        $this->client->request('GET', '/api/docs', server: ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#swagger-data');

        $this->client->request('GET', '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();
        $paths = array_keys(json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['paths']);
        self::assertContains('/api/issues/{key}/transitions', $paths);
    }
}

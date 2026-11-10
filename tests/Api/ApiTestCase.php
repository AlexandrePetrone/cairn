<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Enum\ApiTokenScope;
use App\Entity\Issue;
use App\Message\Command\ApiToken\CreateApiToken;
use App\Message\Command\ApiToken\NewApiToken;
use App\Message\CommandBus;
use App\Repository\IssueRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The API as a program calls it: the shop of ShopScene, a token created
 * through the real command, JSON in and out.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string MANAGER = 'thomas.girard@cairn.test';
    protected const string DEVELOPER = 'nadia.benali@cairn.test';
    /** An internal user without any role on the shop. */
    protected const string OUTSIDER = 'marc.petit@cairn.test';
    protected const string CLIENT = 'claire.martin@maison-dupont.test';

    protected KernelBrowser $client;
    protected ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    protected function tokenFor(string $email, ApiTokenScope $scope = ApiTokenScope::Write, ?\DateTimeImmutable $expiresAt = null): NewApiToken
    {
        $new = self::getContainer()->get(CommandBus::class)->dispatch(new CreateApiToken((int) $this->scene->user($email)->getId(), 'Test', $scope, $expiresAt));
        \assert($new instanceof NewApiToken);

        return $new;
    }

    /**
     * The issue as the database has it now, after the requests.
     */
    protected function issue(string $key): Issue
    {
        return self::getContainer()->get(IssueRepository::class)->findOneByKey($key) ?? throw new \LogicException(\sprintf('No issue %s.', $key));
    }

    /**
     * Sends the request with the secret as bearer token and returns the
     * decoded body, `[]` when there is none.
     *
     * @param array<string, mixed>|null $json
     *
     * @return array<array-key, mixed>
     */
    protected function api(string $method, string $uri, ?string $secret, ?array $json = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $secret) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$secret;
        }
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
        }
        $this->client->request($method, $uri, server: $server, content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }
}

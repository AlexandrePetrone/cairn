<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Gives a test the identity provider that SingleSignOnEnvironment
 * configures, answered by a mock HTTP client: its discovery document, its
 * keys, its token and UserInfo endpoints. The ID token is signed with a
 * key made for the test.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\WebTestCase
 */
trait SingleSignOnProvider
{
    use SingleSignOnEnvironment;

    private static ?JWK $ssoKey = null;

    /** @var array<string, mixed> what the provider says of whoever signs in */
    private array $ssoClaims = [];

    /**
     * The HTTP client of the application now reaches the provider of the
     * test and nothing else. The client of the test must not reboot its
     * kernel between requests, or the next one would call the real one.
     */
    private function mockProvider(): void
    {
        self::getContainer()->get('cache.security.discovery')->clear();
        self::getContainer()->set('http_client', new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($url, $options)));
    }

    /**
     * Walks the whole flow as a browser would: to the provider, which
     * authenticates someone it describes with `$claims`, and back.
     *
     * @param array<string, mixed> $claims `sub`, `email`, `email_verified`…
     */
    private function signInThroughProvider(array $claims): void
    {
        $this->client->request('GET', '/login/sso');
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::SSO_ISSUER.'/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(self::SSO_CLIENT, $query['client_id']);
        self::assertSame('http://localhost/login/sso/callback', $query['redirect_uri']);

        $this->ssoClaims = $claims + ['nonce' => $query['nonce']];
        $this->client->request('GET', '/login/sso/callback', ['state' => $query['state'], 'code' => 'the-code']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $url, array $options): MockResponse
    {
        $json = static fn (array $body): MockResponse => new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type' => 'application/json']]);

        return match ((string) parse_url($url, \PHP_URL_PATH)) {
            '/.well-known/openid-configuration' => $json([
                'issuer' => self::SSO_ISSUER,
                'authorization_endpoint' => self::SSO_ISSUER.'/authorize',
                'token_endpoint' => self::SSO_ISSUER.'/token',
                'userinfo_endpoint' => self::SSO_ISSUER.'/userinfo',
                'jwks_uri' => self::SSO_ISSUER.'/keys',
                'response_types_supported' => ['code'],
                'subject_types_supported' => ['public'],
                'id_token_signing_alg_values_supported' => ['RS256'],
            ]),
            '/keys' => $json(['keys' => [self::ssoKey()->toPublic()->all()]]),
            '/token' => $json(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => $this->idToken()]),
            '/userinfo' => $json(array_diff_key($this->ssoClaims, ['nonce' => true])),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function idToken(): string
    {
        $now = time();
        $payload = ['iss' => self::SSO_ISSUER, 'aud' => self::SSO_CLIENT, 'iat' => $now, 'exp' => $now + 300] + $this->ssoClaims;
        $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
            ->create()
            ->withPayload(json_encode($payload, \JSON_THROW_ON_ERROR))
            ->addSignature(self::ssoKey(), ['alg' => 'RS256', 'kid' => 'test', 'typ' => 'JWT'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    private static function ssoKey(): JWK
    {
        return self::$ssoKey ??= JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test']);
    }
}

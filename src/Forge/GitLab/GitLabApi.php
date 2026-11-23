<?php

declare(strict_types=1);

namespace App\Forge\GitLab;

use App\Entity\Enum\Forge;
use App\Forge\ForgeApi;
use App\Forge\ForgeApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitLab's REST API, with the instance token of `FORGE_GITLAB_TOKEN` on
 * the instance of `FORGE_GITLAB_URL`. Project webhooks only: the hook
 * listens to pushes and merge requests and sends the secret as
 * `X-Gitlab-Token`. GitLab answers 404 both for a hook already gone and
 * for a project the token lost access to; both are taken as gone. With
 * `FORGE_GITLAB_HOOK_SECRET`, a system hook of the instance delivers every
 * repository instead (`GitLabRequestParser` checks it) and nothing is
 * installed.
 */
final class GitLabApi implements ForgeApi
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'FORGE_GITLAB_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'FORGE_GITLAB_TOKEN')]
        #[\SensitiveParameter]
        private readonly string $token,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
        #[Autowire(env: 'FORGE_GITLAB_HOOK_SECRET')]
        #[\SensitiveParameter]
        private readonly string $hookSecret = '',
    ) {
    }

    public function forge(): Forge
    {
        return Forge::GitLab;
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->token) && '' !== trim($this->baseUrl);
    }

    public function hasInstanceHook(): bool
    {
        return '' !== trim($this->hookSecret);
    }

    public function deliveryUrl(): string
    {
        return rtrim($this->defaultUri, '/').$this->urlGenerator->generate('_webhook_controller', ['type' => Forge::GitLab->value]);
    }

    public function installHook(string $repositoryPath, #[\SensitiveParameter] string $secret): string
    {
        $hook = $this->call('POST', \sprintf('/projects/%s/hooks', rawurlencode($repositoryPath)), $this->hook($secret));
        $id = $hook['id'] ?? null;
        if (!\is_int($id) && !\is_string($id)) {
            throw new ForgeApiException('GitLab created the hook without returning its id.');
        }

        return (string) $id;
    }

    public function updateHookSecret(string $repositoryPath, string $hookId, #[\SensitiveParameter] string $secret): void
    {
        $this->call('PUT', \sprintf('/projects/%s/hooks/%s', rawurlencode($repositoryPath), rawurlencode($hookId)), $this->hook($secret));
    }

    public function uninstallHook(string $repositoryPath, string $hookId): void
    {
        $this->call('DELETE', \sprintf('/projects/%s/hooks/%s', rawurlencode($repositoryPath), rawurlencode($hookId)), null, [204, 404]);
    }

    /**
     * The hook as GitLab wants it, in full on creation and on edit alike (`url` is required on both).
     *
     * @return array<string, mixed>
     */
    private function hook(#[\SensitiveParameter] string $secret): array
    {
        return ['url' => $this->deliveryUrl(), 'token' => $secret, 'push_events' => true, 'merge_requests_events' => true, 'enable_ssl_verification' => true];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param list<int>                 $silent statuses that end the call with nothing to read
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $json, array $silent = []): array
    {
        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').'/api/v4'.$path, [
                'headers' => ['PRIVATE-TOKEN' => $this->token],
                'json' => $json,
                'max_redirects' => 0,
                'timeout' => 10,
            ]);

            return \in_array($response->getStatusCode(), $silent, true) ? [] : $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new ForgeApiException(\sprintf('GitLab refused %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }
    }
}

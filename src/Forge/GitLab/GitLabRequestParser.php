<?php

declare(strict_types=1);

namespace App\Forge\GitLab;

use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use App\Entity\Enum\MergeRequestState;
use App\Forge\Event\CommitPayload;
use App\Forge\Event\MergeRequestPayload;
use App\Forge\Event\MergeRequestReceived;
use App\Forge\Event\PushReceived;
use App\Repository\CodeRepositoryRepository;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\HeaderRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Reads a GitLab webhook delivery. The repository named in the payload
 * must be linked to a project, and the `X-Gitlab-Token` header must be
 * the secret of that link: there is no instance-wide secret, so the
 * `$secret` the framework passes is ignored. One neutral event per link
 * matched, since each is bound to its project. A kind Cairn does not read
 * (a tag, a note, a pipeline) is accepted and ignored: GitLab disables a
 * webhook after repeated 4xx answers.
 */
final class GitLabRequestParser extends AbstractRequestParser
{
    /** GitLab itself sends at most 20 commits per push (`total_commits_count` says the real number). */
    private const int MAX_COMMITS = 100;

    public function __construct(
        private readonly CodeRepositoryRepository $repositories,
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        // The body is not looked at before the token is: toArray() rejects a malformed one (406) once authenticated.
        return new ChainRequestMatcher([
            new HeaderRequestMatcher(['X-Gitlab-Event', 'X-Gitlab-Token']),
            new MethodRequestMatcher('POST'),
        ]);
    }

    /**
     * @return list<RemoteEvent>
     */
    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): array
    {
        // The token has the shape of a secret of ours before the body is read.
        $token = (string) $request->headers->get('X-Gitlab-Token');
        if (1 !== preg_match('/^[0-9a-f]{40}$/', $token)) {
            throw new RejectWebhookException(403, 'Unknown repository or invalid token.');
        }
        $payload = $request->toArray();
        $path = self::str($payload['project']['path_with_namespace'] ?? null, 255);
        $links = array_values(array_filter($this->repositories->findByPath(Forge::GitLab, $path), static fn (CodeRepository $link): bool => $link->acceptsToken($token)));
        if ([] === $links) {
            throw new RejectWebhookException(403, 'Unknown repository or invalid token.');
        }

        $id = self::str($request->headers->get('X-Gitlab-Event-UUID') ?? (string) Uuid::v4(), 64);
        $events = match (self::str($payload['object_kind'] ?? null, 40)) {
            'push' => $this->push($payload, $links),
            'merge_request' => $this->mergeRequest($payload, $links),
            default => null,
        };

        return $events ?? [new RemoteEvent('ignored', $id, [])];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<CodeRepository> $links
     *
     * @return list<RemoteEvent>|null null when there is nothing to record
     */
    private function push(array $payload, array $links): ?array
    {
        $ref = self::str($payload['ref'] ?? null, 255);
        if (!str_starts_with($ref, 'refs/heads/')) {
            return null;
        }
        $branch = substr($ref, \strlen('refs/heads/'));
        $commits = [];
        foreach (\array_slice((array) ($payload['commits'] ?? []), 0, self::MAX_COMMITS) as $commit) {
            if (!\is_array($commit) || '' === $sha = self::str($commit['id'] ?? null, 64)) {
                continue;
            }
            $commits[] = new CommitPayload($sha, self::str($commit['title'] ?? null, 255), self::str($commit['message'] ?? null, 10000), self::str($commit['url'] ?? null, 500), self::str($commit['author']['name'] ?? null, 120));
        }
        if ([] === $commits) {
            return null;
        }
        $branchUrl = self::str($payload['project']['web_url'] ?? null, 200).'/-/tree/'.implode('/', array_map(rawurlencode(...), explode('/', $branch)));

        return array_map(static fn (CodeRepository $link): RemoteEvent => new PushReceived((string) Uuid::v4(), (int) $link->getId(), $branch, $branchUrl, $commits), $links);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<CodeRepository> $links
     *
     * @return list<RemoteEvent>|null
     */
    private function mergeRequest(array $payload, array $links): ?array
    {
        $attributes = (array) ($payload['object_attributes'] ?? []);
        $state = match (self::str($attributes['state'] ?? null, 20)) {
            'opened', 'locked' => MergeRequestState::Opened,
            'merged' => MergeRequestState::Merged,
            'closed' => MergeRequestState::Closed,
            default => null,
        };
        $number = $attributes['iid'] ?? null;
        if (null === $state || !\is_int($number)) {
            return null;
        }
        $mergeRequest = new MergeRequestPayload(
            $number,
            self::str($attributes['title'] ?? null, 255),
            self::str($attributes['description'] ?? null, 10000),
            self::str($attributes['url'] ?? null, 500),
            self::str($attributes['source_branch'] ?? null, 255),
            $state,
            self::str($payload['user']['name'] ?? null, 120),
        );

        return array_map(static fn (CodeRepository $link): RemoteEvent => new MergeRequestReceived((string) Uuid::v4(), (int) $link->getId(), $mergeRequest), $links);
    }

    /** A text of the payload, fit for a column: scalar only, no NUL byte, trimmed, cut to `$max`. */
    private static function str(mixed $value, int $max): string
    {
        return \is_scalar($value) ? mb_substr(trim(str_replace("\0", '', (string) $value)), 0, $max) : '';
    }
}

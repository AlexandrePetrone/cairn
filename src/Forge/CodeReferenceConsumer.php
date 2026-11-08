<?php

declare(strict_types=1);

namespace App\Forge;

use App\Forge\Event\MergeRequestReceived;
use App\Forge\Event\PushReceived;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Message\CommandBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Turns a delivery into one command per issue key mentioned: the commits
 * of a push grouped by key, a merge request for each key of its title,
 * description and source branch. The handlers decide what the key is
 * worth (an issue of the repository's project, or nothing). Each command
 * runs in its own transaction and a refusal is logged, never rethrown:
 * one bad key must not block the others nor have the delivery retried.
 */
#[AsRemoteEventConsumer('gitlab')]
final class CodeReferenceConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly IssueKeyParser $keys,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        if ($event instanceof PushReceived) {
            $byKey = [];
            foreach ($event->commits as $commit) {
                foreach ($this->keys->keys($commit->message) as $key) {
                    $byKey[$key][] = $commit;
                }
            }
            foreach ($byKey as $key => $commits) {
                $this->dispatch(new RecordCommits($event->codeRepositoryId, $key, $event->branch, $event->branchUrl, $commits), $event);
            }
        } elseif ($event instanceof MergeRequestReceived) {
            $mergeRequest = $event->mergeRequest;
            foreach ($this->keys->keys($mergeRequest->title, $mergeRequest->description, $mergeRequest->sourceBranch) as $key) {
                $this->dispatch(new RecordMergeRequest($event->codeRepositoryId, $key, $mergeRequest), $event);
            }
        }
    }

    private function dispatch(object $command, RemoteEvent $event): void
    {
        try {
            $this->commands->dispatch($command);
        } catch (\DomainException|ValidationFailedException $e) {
            $this->logger->warning('A forge delivery was refused for one issue.', ['delivery' => $event->getId(), 'command' => $command::class, 'reason' => $e->getMessage()]);
        }
    }
}

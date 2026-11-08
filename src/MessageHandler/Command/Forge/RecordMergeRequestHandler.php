<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Forge;

use App\Entity\CodeReference;
use App\Entity\Enum\CodeReferenceKind;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\MergeRequestState;
use App\Entity\JournalEntry;
use App\Event\CodeReferenced;
use App\Message\Command\Forge\RecordMergeRequest;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Repository\CodeReferenceRepository;
use App\Repository\CodeRepositoryRepository;
use App\Repository\IssueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the merge request the first time it is seen, with the state it
 * is in; refreshes it afterwards, and journals only a change of state.
 * Opened again after being closed is journaled as reopened. Merged is
 * final: a late or replayed delivery never brings a merged request back.
 * A key the repository may not write on ends quietly, as for commits.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RecordMergeRequestHandler
{
    public function __construct(
        private readonly CodeRepositoryRepository $repositories,
        private readonly IssueRepository $issues,
        private readonly CodeReferenceRepository $references,
        private readonly IssueHandlerSupport $support,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordMergeRequest $command): ?CodeReference
    {
        $repository = $this->repositories->find($command->codeRepositoryId);
        $issue = $this->issues->findOneByKey($command->issueKey);
        if (null === $repository || null === $issue || $issue->getProject() !== $repository->getProject() || $issue->getProject()->isReadOnly()) {
            $this->logger->info('Forge delivery ignored for this key.', ['repository' => $command->codeRepositoryId, 'key' => $command->issueKey]);

            return null;
        }

        $mergeRequest = $command->mergeRequest;
        $branch = '' !== $mergeRequest->sourceBranch ? $mergeRequest->sourceBranch : null;
        $reference = $this->references->findOne($issue, $repository->getForge(), $repository->getPath(), CodeReferenceKind::MergeRequest, (string) $mergeRequest->number);
        $previous = $reference?->getState();
        if (null === $reference) {
            $reference = CodeReference::mergeRequest($issue, $repository->getForge(), $repository->getPath(), (string) $mergeRequest->number, $mergeRequest->title, $mergeRequest->url, $branch, $mergeRequest->authorName, $mergeRequest->state);
            $this->entityManager->persist($reference);
        } elseif (MergeRequestState::Merged === $previous) {
            return $reference;
        } else {
            $reference->refresh($mergeRequest->title, $mergeRequest->url, $branch, $mergeRequest->state);
            if ($previous === $mergeRequest->state) {
                return $reference;
            }
        }

        $type = match ($mergeRequest->state) {
            MergeRequestState::Merged => JournalEntryType::MergeRequestMerged,
            MergeRequestState::Closed => JournalEntryType::MergeRequestClosed,
            MergeRequestState::Opened => MergeRequestState::Closed === $previous ? JournalEntryType::MergeRequestReopened : JournalEntryType::MergeRequestOpened,
        };
        $this->support->record(new CodeReferenced($issue, [JournalEntry::mergeRequest($issue, $reference, $type)]));

        return $reference;
    }
}

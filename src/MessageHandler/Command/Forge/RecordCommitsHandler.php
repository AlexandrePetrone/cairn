<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Forge;

use App\Entity\CodeReference;
use App\Entity\Enum\CodeReferenceKind;
use App\Entity\JournalEntry;
use App\Event\CodeReferenced;
use App\Message\Command\Forge\RecordCommits;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Repository\CodeReferenceRepository;
use App\Repository\CodeRepositoryRepository;
use App\Repository\IssueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the commits the issue does not know yet and journals them as one
 * entry. A commit pushed again (another branch, a rebase) finds its row
 * and is left out; the row keeps the branch it was first seen on. A key
 * the repository may not write on (unknown, of another project, of a
 * read-only project) ends quietly: a delivery is not retried for it.
 * Two workers handling the same commit at once would both miss the row;
 * the unique constraint refuses the second, whose retry finds it.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RecordCommitsHandler
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

    /**
     * @return list<CodeReference> the commits new to the issue
     */
    public function __invoke(RecordCommits $command): array
    {
        $repository = $this->repositories->find($command->codeRepositoryId);
        $issue = $this->issues->findOneByKey($command->issueKey);
        if (null === $repository || null === $issue || $issue->getProject() !== $repository->getProject() || $issue->getProject()->isReadOnly()) {
            $this->logger->info('Forge delivery ignored for this key.', ['repository' => $command->codeRepositoryId, 'key' => $command->issueKey]);

            return [];
        }

        $new = [];
        foreach ($command->commits as $commit) {
            if (isset($new[$commit->sha]) || null !== $this->references->findOne($issue, $repository->getForge(), $repository->getPath(), CodeReferenceKind::Commit, $commit->sha)) {
                continue;
            }
            $reference = CodeReference::commit($issue, $repository->getForge(), $repository->getPath(), $commit->sha, $commit->title, $commit->url, $command->branch, $commit->authorName);
            $this->entityManager->persist($reference);
            $new[$commit->sha] = $reference;
        }
        $new = array_values($new);
        if ([] !== $new) {
            $this->support->record(new CodeReferenced($issue, [JournalEntry::commitsPushed($issue, $command->branch, $command->branchUrl, $new)]));
        }

        return $new;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Release;

use App\Entity\JournalEntry;
use App\Event\IssueUpdated;
use App\Message\Command\Release\DeleteRelease;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\IssueRepository;
use App\Repository\ReleaseRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes the release for good. Each issue it held loses it as if the
 * actor had taken it out by hand: one journal line with the name the
 * release had, and the usual event, so its followers read it in the mail
 * of the project. Whoever manages the releases decides for all of them,
 * the private issues included, without `issue.set_release` being asked.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteReleaseHandler
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly IssueRepository $issues,
        private readonly IssueHandlerSupport $support,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteRelease $command): void
    {
        $release = $this->releases->find($command->releaseId) ?? throw ReleaseException::notFound('Release', $command->releaseId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ReleaseManage, $release->getProject());
        $actor = $this->support->actor($command->actorId);

        foreach ($this->issues->findBy(['release' => $release]) as $issue) {
            $issue->scheduleIn(null);
            $this->support->record(new IssueUpdated($issue, $actor, [JournalEntry::changed($issue, $actor, 'release', $release->getName(), null)]));
        }
        $this->entityManager->remove($release);
    }
}

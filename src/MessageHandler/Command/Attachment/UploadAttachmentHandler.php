<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Attachment;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\JournalEntry;
use App\Message\Command\Attachment\UploadAttachment;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Repository\ProjectRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sending a file goes with writing. A pending file needs the right to
 * create, edit or comment in the project; a file added to an issue
 * directly (its Files block) belongs with its text, so only the issue's
 * author adds it. Journaled.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UploadAttachmentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly ProjectRepository $projects,
        private readonly AccessControl $access,
        private readonly AttachmentStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UploadAttachment $command): Attachment
    {
        $actor = $this->support->actor($command->actorId);
        $project = $this->projects->find($command->projectId) ?? throw IssueException::notFound('Project', $command->projectId);

        if (null === $command->issueKey) {
            $issue = null;
            $this->support->denyUnless(
                $this->access->isGranted($actor, Permission::IssueCreate, $project)
                || $this->access->isGranted($actor, Permission::IssueEdit, $project)
                || $this->access->isGranted($actor, Permission::CommentCreate, $project),
                'upload to this project',
            );
        } else {
            $issue = $this->support->visibleIssue($actor, $command->issueKey);
            $this->support->denyUnless($issue->getProject() === $project, 'upload to an issue of another project');
            $this->support->denyUnless($this->access->canEditIssue($actor, $issue), 'add files to an issue written by someone else');
        }

        $file = $command->file;
        $attachment = new Attachment(
            $project,
            $issue,
            $actor,
            self::filename($file->getClientOriginalName(), (string) $file->guessExtension()),
            (string) $file->getMimeType(),
            (int) $file->getSize(),
        );
        $this->storage->store($file, $attachment);
        $this->entityManager->persist($attachment);
        if (null !== $issue) {
            $issue->touch();
            $this->entityManager->persist(JournalEntry::attachmentAdded($issue, $actor, $attachment));
        }

        return $attachment;
    }

    /**
     * A name safe in a URL, keeping the one the browser gave (a pasted
     * screenshot comes as `image.png`, an export keeps its `.log`), with
     * the guessed extension when the given one is missing or unusable.
     */
    private static function filename(string $original, string $guessed): string
    {
        $safe = static fn (string $part): string => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $part), '-.');
        $name = mb_substr($safe(pathinfo($original, \PATHINFO_FILENAME)), 0, 100);
        $extension = strtolower($safe(pathinfo($original, \PATHINFO_EXTENSION)));
        if ('' === $extension || \strlen($extension) > 10) {
            $extension = '' === $guessed ? 'bin' : $guessed;
        }

        return ('' === $name ? 'file' : $name).'.'.$extension;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Attachment;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Repository\ProjectRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sending a file goes with writing: creating an issue in the project, or
 * editing or commenting a visible issue.
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
            $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueCreate, $project), 'upload to this project');
        } else {
            $issue = $this->support->visibleIssue($actor, $command->issueKey);
            $this->support->denyUnless($issue->getProject() === $project, 'upload to an issue of another project');
            $this->support->denyUnless(
                $this->access->isGranted($actor, Permission::IssueEdit, $project) || $this->access->isGranted($actor, Permission::CommentCreate, $project),
                'upload to this issue',
            );
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

        return $attachment;
    }

    /**
     * A name safe in a URL, keeping the one the browser gave when it has
     * one: a pasted screenshot comes as `image.png`.
     */
    private static function filename(string $original, string $extension): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, \PATHINFO_FILENAME)), '-.');

        return ('' === $name ? 'image' : mb_substr($name, 0, 100)).'.'.('' === $extension ? 'bin' : $extension);
    }
}

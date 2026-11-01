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
 * Sending a file goes with writing: a pending file needs the right to
 * create, edit or comment in the project.
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

        $this->support->denyUnless(
            $this->access->isGranted($actor, Permission::IssueCreate, $project)
            || $this->access->isGranted($actor, Permission::IssueEdit, $project)
            || $this->access->isGranted($actor, Permission::CommentCreate, $project),
            'upload to this project',
        );

        $attachment = Attachment::fromUpload($command->file, $project, null, $actor);
        $this->storage->store($command->file, $attachment);
        $this->entityManager->persist($attachment);

        return $attachment;
    }
}

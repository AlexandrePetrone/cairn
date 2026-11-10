<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attachment\AttachmentStorage;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Serves an attachment to whoever may see it (AccessControl::canSeeAttachment).
 * Anything else is not found, so that private issues and comments cannot be
 * probed.
 */
final class AttachmentController extends AbstractController
{
    #[Route(
        '/attachments/{uuid}/{filename}',
        name: 'app_attachment_show',
        requirements: ['uuid' => Requirement::UUID_V4, 'filename' => '[A-Za-z0-9._-]+'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $uuid,
        string $filename,
        #[CurrentUser]
        User $user,
        AttachmentRepository $attachments,
        AccessControl $access,
        AttachmentStorage $storage,
    ): BinaryFileResponse {
        $attachment = $attachments->findOneByUuid(Uuid::fromString($uuid));
        if (null === $attachment || $attachment->getFilename() !== $filename) {
            throw $this->createNotFoundException('No such attachment.');
        }
        if (!$access->canSeeAttachment($user, $attachment)) {
            throw $this->createNotFoundException('No such attachment for this user.');
        }

        return $storage->serve($attachment);
    }
}

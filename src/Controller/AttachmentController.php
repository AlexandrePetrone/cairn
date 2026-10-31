<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attachment\AttachmentStorage;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Serves an attachment to whoever may see its issue; before an issue
 * claims it, to its uploader only. Anything else is not found, so that
 * private issues cannot be probed.
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
        $issue = $attachment->getIssue();
        $visible = null === $issue
            ? $attachment->getUploadedBy()->getId() === $user->getId()
            : $access->canSee($user, $issue);
        if (!$visible) {
            throw $this->createNotFoundException('No such attachment for this user.');
        }

        $response = new BinaryFileResponse($storage->path($attachment), headers: [
            'Content-Type' => $attachment->getMediaType(),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $attachment->getFilename());
        $response->setAutoLastModified();
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}

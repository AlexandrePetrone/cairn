<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Receives an image pasted in an editor and answers with its URL. Only
 * the editor calls it, with the `X-Requested-With` header that a
 * cross-origin form cannot send. The file is pending until the text that
 * points to it is saved. Every answer is JSON, a failure carrying the
 * `error` the editor shows.
 */
#[IsGranted('view', subject: 'project')]
final class UploadAttachmentController extends AbstractController
{
    #[Route(
        '/{workspaceKey}/{projectKey}/attachments',
        name: 'app_attachment_upload',
        requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT],
        methods: ['POST'],
    )]
    public function __invoke(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $user,
        Request $request,
        CommandBus $commands,
        TranslatorInterface $translator,
    ): JsonResponse {
        $file = $request->files->get('file');
        if (!$request->isXmlHttpRequest() || !$file instanceof UploadedFile) {
            return $this->json(['error' => $translator->trans('editor.upload_failed')], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var Attachment $attachment */
            $attachment = $commands->dispatch(new UploadAttachment((int) $user->getId(), (int) $project->getId(), $file));
        } catch (ValidationFailedException $e) {
            return $this->json(['error' => (string) $e->getViolations()->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            return $this->json(['error' => $translator->trans('editor.upload_refused')], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'url' => $this->generateUrl('app_attachment_show', ['uuid' => $attachment->getUuid()->toRfc4122(), 'filename' => $attachment->getFilename()]),
            'name' => $attachment->getFilename(),
        ], Response::HTTP_CREATED);
    }
}

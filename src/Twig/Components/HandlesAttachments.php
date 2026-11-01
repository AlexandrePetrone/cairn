<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\User;
use App\Message\Command\Attachment\AddAttachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * For live components that send or remove files. Files come from an
 * `<input type="file" name="files[]">` that triggers `files|<action>`
 * (see the file-picker controller); each is sent pending
 * (UploadAttachment) or straight to an issue (AddAttachment). A refusal comes back as a message for
 * the component to show, with the name of the file it concerns. The
 * component has a `translator`.
 */
trait HandlesAttachments
{
    /**
     * @return array{list<Attachment>, ?string} the files stored, and why one was refused
     */
    private function sendFiles(Request $request, CommandBus $commands, User $actor, Project $project, ?string $issueKey = null): array
    {
        $sent = [];
        foreach ($request->files->all('files') as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            try {
                $attachment = $commands->dispatch(null === $issueKey
                    ? new UploadAttachment((int) $actor->getId(), (int) $project->getId(), $file)
                    : new AddAttachment((int) $actor->getId(), $issueKey, $file));
            } catch (ValidationFailedException $e) {
                return [$sent, \sprintf('%s : %s', $file->getClientOriginalName(), $e->getViolations()->get(0)->getMessage())];
            } catch (IssueException|AccessDeniedException) {
                return [$sent, $this->translator->trans('attachment.refused')];
            }
            if ($attachment instanceof Attachment) {
                $sent[] = $attachment;
            }
        }

        return [$sent, null];
    }
}

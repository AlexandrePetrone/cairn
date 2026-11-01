<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\User;
use App\Message\Command\Attachment\RemoveAttachment;
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
 * (see the file-picker controller); each goes through UploadAttachment,
 * pending or straight to an issue. A refusal comes back as a message for
 * the component to show. The component has a `translator`.
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
                $attachment = $commands->dispatch(new UploadAttachment((int) $actor->getId(), (int) $project->getId(), $issueKey, $file));
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

    /**
     * @return ?string why the file was not removed
     */
    private function removeFile(CommandBus $commands, User $actor, string $uuid): ?string
    {
        try {
            $commands->dispatch(new RemoveAttachment((int) $actor->getId(), $uuid));
        } catch (IssueException|AccessDeniedException) {
            return $this->translator->trans('issue.edit.refused');
        }

        return null;
    }
}

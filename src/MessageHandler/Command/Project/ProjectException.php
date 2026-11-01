<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Project;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a project cannot be carried out as asked. The message is for the logs; the administration
 * shows the translated reason.
 */
final class ProjectException extends \DomainException implements TranslatableInterface
{
    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what, int $id): self
    {
        return new self(\sprintf('%s %d not found.', $what, $id), 'admin.refused.not_found');
    }

    public static function keyTaken(string $key, string $workspaceName): self
    {
        return new self(\sprintf('A project of "%s" already uses the key "%s".', $workspaceName, $key), 'admin.refused.project_key_taken', ['%key%' => $key, '%workspace%' => $workspaceName]);
    }

    public static function archivedWorkspace(string $workspaceName): self
    {
        return new self(\sprintf('Workspace "%s" is archived.', $workspaceName), 'admin.refused.archived_workspace', ['%workspace%' => $workspaceName]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

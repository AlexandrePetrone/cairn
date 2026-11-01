<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Workspace;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a workspace cannot be carried out as asked. The message is for the logs; the administration
 * shows the translated reason.
 */
final class WorkspaceException extends \DomainException implements TranslatableInterface
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

    public static function notFound(int $id): self
    {
        return new self(\sprintf('Workspace %d not found.', $id), 'admin.refused.not_found');
    }

    public static function keyTaken(string $key): self
    {
        return new self(\sprintf('A workspace already uses the key "%s".', $key), 'admin.refused.workspace_key_taken', ['%key%' => $key]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

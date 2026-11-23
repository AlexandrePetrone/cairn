<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Forge;

use App\Entity\Enum\Forge;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a linked repository cannot be carried out as asked. The
 * message is for the logs; the repositories page shows the translated
 * reason.
 */
final class CodeRepositoryException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('%s %d not found.', $what, $id), 'code_repository.refused.not_found');
    }

    public static function forgeUnavailable(Forge $forge): self
    {
        return new self(\sprintf('The forge "%s" is not supported yet.', $forge->value), 'code_repository.refused.forge_unavailable');
    }

    public static function alreadyLinked(string $path): self
    {
        return new self(\sprintf('The repository "%s" is already linked to this project.', $path), 'code_repository.refused.already_linked', ['%path%' => $path]);
    }

    public static function apiNotConfigured(Forge $forge): self
    {
        return new self(\sprintf('No instance token for "%s": install the webhook by hand.', $forge->value), 'code_repository.refused.api_not_configured');
    }

    public static function instanceHook(Forge $forge): self
    {
        return new self(\sprintf('The instance hook of "%s" already delivers every repository: no webhook to install.', $forge->value), 'code_repository.refused.instance_hook');
    }

    public static function forgeRefused(\Throwable $cause): self
    {
        return new self('The forge refused the webhook call: '.$cause->getMessage(), 'code_repository.refused.forge_refused');
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

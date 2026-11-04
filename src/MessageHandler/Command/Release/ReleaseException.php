<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Release;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a release cannot be carried out as asked. The message is
 * for the logs; the releases page shows the translated reason.
 */
final class ReleaseException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('%s %d not found.', $what, $id), 'release.refused.not_found');
    }

    public static function nameTaken(string $name): self
    {
        return new self(\sprintf('The project already has a release named "%s".', $name), 'release.refused.name_taken', ['%name%' => $name]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

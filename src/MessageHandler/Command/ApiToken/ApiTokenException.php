<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\ApiToken;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on an API token cannot be carried out as asked. The message
 * is for the logs; the tokens page shows the translated reason.
 */
final class ApiTokenException extends \DomainException implements TranslatableInterface
{
    private function __construct(
        string $message,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what, int $id): self
    {
        return new self(\sprintf('%s %d not found.', $what, $id), 'api_token.refused.not_found');
    }

    public static function alreadyExpired(): self
    {
        return new self('A token cannot expire in the past.', 'api_token.refused.already_expired');
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, [], null, $locale);
    }
}

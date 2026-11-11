<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The link to choose a password cannot be used: expired, already used,
 * forged or unknown. The message is for the logs; the page shows the
 * translated reason, which never says more than that.
 */
final class PasswordLinkException extends \DomainException implements TranslatableInterface
{
    private function __construct(
        string $message,
        private readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function expired(?\Throwable $previous = null): self
    {
        return new self('The password link has expired.', 'auth.password.refused.expired', $previous);
    }

    public static function invalid(?\Throwable $previous = null): self
    {
        return new self('The password link is invalid or was already used.', 'auth.password.refused.invalid', $previous);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, [], null, $locale);
    }
}

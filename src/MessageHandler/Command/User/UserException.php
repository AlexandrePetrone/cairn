<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a user account cannot be carried out as asked. The message is for the logs; the administration
 * shows the translated reason.
 */
final class UserException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('User %d not found.', $id), 'admin.refused.not_found');
    }

    public static function emailTaken(string $email): self
    {
        return new self(\sprintf('An account already uses "%s".', $email), 'admin.refused.email_taken', ['%email%' => $email]);
    }

    public static function lastAdministrator(): self
    {
        return new self('The last active administrator must stay one.', 'admin.refused.last_admin');
    }

    /**
     * @param list<string> $where the workspaces and projects of those roles
     */
    public static function holdsInternalRoles(string $userName, array $where): self
    {
        return new self(\sprintf('%s holds internal roles on: %s.', $userName, implode(', ', $where)), 'admin.refused.holds_internal_roles', ['%name%' => $userName, '%where%' => implode(', ', $where)]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

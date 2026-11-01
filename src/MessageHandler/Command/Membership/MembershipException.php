<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Membership;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A role cannot be granted or taken back as asked. The message is for the logs; the administration
 * shows the translated reason.
 */
final class MembershipException extends \DomainException implements TranslatableInterface
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

    public static function unknownRole(string $role): self
    {
        return new self(\sprintf('Role "%s" does not exist in the configuration.', $role), 'admin.refused.unknown_role', ['%role%' => $role]);
    }

    public static function internalOnlyRole(string $roleLabel, string $userName): self
    {
        return new self(\sprintf('Role "%s" cannot be granted to %s, who is external.', $roleLabel, $userName), 'admin.refused.internal_only_role', ['%role%' => $roleLabel, '%name%' => $userName]);
    }

    public static function notWiderThanInherited(string $roleLabel, string $inheritedLabel): self
    {
        return new self(\sprintf('Role "%s" does not widen the role "%s" inherited from the workspace.', $roleLabel, $inheritedLabel), 'admin.refused.not_wider', ['%role%' => $roleLabel, '%inherited%' => $inheritedLabel]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

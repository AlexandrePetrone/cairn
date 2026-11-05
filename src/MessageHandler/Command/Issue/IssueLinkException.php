<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A link cannot be made or removed as asked. The message is for the logs;
 * the links block of the issue shows the translated reason.
 */
final class IssueLinkException extends \DomainException implements TranslatableInterface
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

    /**
     * Also an issue the actor may not see, so that private issues cannot
     * be probed.
     */
    public static function issueNotFound(string $key): self
    {
        return new self(\sprintf('Issue "%s" not found.', $key), 'issue.links.refused.not_found', ['%key%' => $key]);
    }

    public static function linkNotFound(int $id): self
    {
        return new self(\sprintf('Link %d not found.', $id), 'issue.links.refused.link_not_found');
    }

    public static function unknownType(string $typeId): self
    {
        return new self(\sprintf('Link type "%s" does not exist in the configuration.', $typeId), 'issue.links.refused.unknown_type');
    }

    public static function itself(string $key): self
    {
        return new self(\sprintf('%s cannot be linked to itself.', $key), 'issue.links.refused.itself');
    }

    public static function alreadyLinked(string $key, string $otherKey): self
    {
        return new self(\sprintf('%s and %s are already linked so.', $key, $otherKey), 'issue.links.refused.already', ['%key%' => $otherKey]);
    }

    public static function otherProject(string $otherKey): self
    {
        return new self(\sprintf('%s belongs to another project: internal users only link across projects.', $otherKey), 'issue.links.refused.other_project', ['%key%' => $otherKey]);
    }

    public static function readOnlyProject(string $otherKey): self
    {
        return new self(\sprintf('The project of %s is archived.', $otherKey), 'issue.links.refused.read_only', ['%key%' => $otherKey]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

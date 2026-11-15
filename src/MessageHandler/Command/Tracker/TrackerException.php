<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tracker;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a tracker cannot be carried out as asked. The message is
 * for the logs; the project configuration shows the translated reason.
 */
final class TrackerException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('%s %d not found.', $what, $id), 'project.settings.refused.not_found');
    }

    public static function unknownWorkflow(string $workflowId): self
    {
        return new self(\sprintf('Workflow "%s" does not exist in the configuration.', $workflowId), 'project.settings.refused.unknown_workflow', ['%workflow%' => $workflowId]);
    }

    public static function hasIssues(string $trackerName, int $issues): self
    {
        return new self(\sprintf('Tracker "%s" holds %d issues: it is archived, not deleted.', $trackerName, $issues), 'project.settings.refused.tracker_has_issues', ['%name%' => $trackerName, '%count%' => (string) $issues]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why something of the configuration cannot be deleted: what
 * ConfigDeletions answers. A dialog shows it beside a button that cannot
 * be pressed; a handler asked all the same throws it
 * (ConfigChangeException::because()). The message is for the logs, the
 * translated reason for the administration.
 */
final readonly class DeletionRefusal implements TranslatableInterface
{
    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        public string $message,
        public string $reason,
        public array $parameters = [],
    ) {
    }

    public static function roleHeld(string $role, int $count): self
    {
        return new self(\sprintf('%d access(es) carry the role "%s".', $count, $role), 'admin.refused.config.role_held', ['%role%' => $role, '%count%' => (string) $count]);
    }

    /**
     * @param list<string> $actions
     */
    public static function roleTriggersActions(string $role, array $actions): self
    {
        return new self(\sprintf('The role "%s" triggers actions of workflows.', $role), 'admin.refused.config.role_in_actions', ['%role%' => $role, '%actions%' => implode(', ', $actions)]);
    }

    /**
     * @param list<string> $rules
     */
    public static function roleAimedAtByRules(string $role, array $rules): self
    {
        return new self(\sprintf('Automation rules aim at the referent of the role "%s".', $role), 'admin.refused.config.role_in_rules', ['%role%' => $role, '%rules%' => implode(', ', $rules)]);
    }

    /**
     * @param list<string> $workflows
     */
    public static function placeUsed(string $place, array $workflows): self
    {
        return new self(\sprintf('The place "%s" is used by workflows.', $place), 'admin.refused.config.place_used', ['%place%' => $place, '%workflows%' => implode(', ', $workflows)]);
    }

    public static function placeHoldsIssues(string $place, int $count): self
    {
        return new self(\sprintf('%d issue(s) stand on the place "%s".', $count, $place), 'admin.refused.config.place_holds_issues', ['%place%' => $place, '%count%' => (string) $count]);
    }

    /**
     * @param list<string> $workflows
     */
    public static function fieldUsed(string $field, array $workflows): self
    {
        return new self(\sprintf('The field "%s" is used by workflows.', $field), 'admin.refused.config.field_used', ['%field%' => $field, '%workflows%' => implode(', ', $workflows)]);
    }

    public static function linkTypeUsed(string $type, int $count): self
    {
        return new self(\sprintf('%d link(s) are of the type "%s".', $count, $type), 'admin.refused.config.link_type_used', ['%type%' => $type, '%count%' => (string) $count]);
    }

    public static function workflowFollowed(string $workflow, int $trackers): self
    {
        return new self(\sprintf('%d tracker(s) follow the workflow "%s".', $trackers, $workflow), 'admin.refused.config.workflow_followed', ['%workflow%' => $workflow, '%count%' => (string) $trackers]);
    }

    /**
     * @param list<string> $templates
     */
    public static function workflowInTemplates(string $workflow, array $templates): self
    {
        return new self(\sprintf('Project templates use the workflow "%s".', $workflow), 'admin.refused.config.workflow_in_templates', ['%workflow%' => $workflow, '%templates%' => implode(', ', $templates)]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

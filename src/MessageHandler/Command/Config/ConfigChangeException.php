<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A change of the configuration cannot be made as asked: it would break
 * what the data relies on, or leave a workflow that cannot run. The
 * message is for the logs; the administration shows the translated reason.
 */
final class ConfigChangeException extends \DomainException implements TranslatableInterface
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

    public static function notFound(string $what, string $id): self
    {
        return new self(\sprintf('%s "%s" not found.', $what, $id), 'admin.refused.not_found');
    }

    public static function invalidKey(string $key): self
    {
        return new self(\sprintf('"%s" is not a valid key.', $key), 'admin.refused.config.invalid_key', ['%key%' => $key]);
    }

    public static function keyTaken(string $key): self
    {
        return new self(\sprintf('The key "%s" is already used.', $key), 'admin.refused.config.key_taken', ['%key%' => $key]);
    }

    public static function unknownPermission(string $permission): self
    {
        return new self(\sprintf('Permission "%s" does not exist.', $permission), 'admin.refused.config.unknown_permission', ['%permission%' => $permission]);
    }

    public static function permissionForInternalRoles(string $role): self
    {
        return new self(\sprintf('The role "%s" is open to external users; this permission is for internal-only roles.', $role), 'admin.refused.config.permission_internal_only', ['%role%' => $role]);
    }

    public static function roleHeldByExternalUsers(string $role, int $count): self
    {
        return new self(\sprintf('%d external user(s) hold the role "%s".', $count, $role), 'admin.refused.config.role_held_by_externals', ['%role%' => $role, '%count%' => (string) $count]);
    }

    public static function roleHoldsInternalPermissions(string $role): self
    {
        return new self(\sprintf('The role "%s" holds permissions for internal-only roles.', $role), 'admin.refused.config.role_has_internal_permissions', ['%role%' => $role]);
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

    public static function notAPlaceOf(string $place, string $workflow): self
    {
        return new self(\sprintf('"%s" is not a place of the workflow "%s".', $place, $workflow), 'admin.refused.config.not_a_place_of', ['%place%' => $place, '%workflow%' => $workflow]);
    }

    public static function alreadyAPlaceOf(string $place, string $workflow): self
    {
        return new self(\sprintf('"%s" is already a place of the workflow "%s".', $place, $workflow), 'admin.refused.config.already_a_place_of', ['%place%' => $place, '%workflow%' => $workflow]);
    }

    public static function initialPlace(string $place, string $workflow): self
    {
        return new self(\sprintf('"%s" is the initial place of the workflow "%s".', $place, $workflow), 'admin.refused.config.initial_place', ['%place%' => $place, '%workflow%' => $workflow]);
    }

    public static function issuesToMove(string $place, int $count): self
    {
        return new self(\sprintf('%d issue(s) stand on "%s" and no place was given to take them.', $count, $place), 'admin.refused.config.issues_to_move', ['%place%' => $place, '%count%' => (string) $count]);
    }

    public static function otherPlaces(string $workflow): self
    {
        return new self(\sprintf('The order given does not list the places of the workflow "%s".', $workflow), 'admin.refused.config.other_places', ['%workflow%' => $workflow]);
    }

    public static function pairTaken(string $action, string $from, string $to): self
    {
        return new self(\sprintf('The action "%s" already leads from "%s" to "%s".', $action, $from, $to), 'admin.refused.config.pair_taken', ['%action%' => $action, '%from%' => $from, '%to%' => $to]);
    }

    /**
     * The whole configuration, checked once the change is written, would
     * not hold: what no rule above caught.
     */
    public static function invalid(string $detail): self
    {
        return new self('The configuration would be invalid: '.$detail, 'admin.refused.config.invalid', ['%detail%' => $detail]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Security\Permission;

/**
 * Whether a whole definition is sound: each element is well formed (a key,
 * a label, the choices of a field, where an action starts and who triggers
 * it), every reference points to something that exists, and the structure
 * can be run by the workflow engine and the kanban.
 *
 * The one judge of a configuration written at once (InstallConfiguration),
 * and what the tests hold to the commands that change it one element at a
 * time. The definition objects check nothing: they say what they are given.
 */
final class ConfigValidator
{
    /** What a key looks like, as ConfigWriting::key() makes them. */
    private const string KEY = '/^[a-z][a-z0-9_]*$/';

    /**
     * @return list<ConfigViolation>
     */
    public function validate(InstanceDefinition $definition): array
    {
        $violations = [];

        foreach ($definition->roles as $role) {
            array_push($violations, ...self::named('roles', $role->id, $role->label));
        }
        foreach ($definition->places as $place) {
            array_push($violations, ...self::named('places', $place->id, $place->label));
        }
        foreach ($definition->fields as $field) {
            array_push($violations, ...self::named('fields', $field->id, $field->label), ...self::validateChoices($field));
        }
        foreach ($definition->links as $link) {
            array_push($violations, ...self::named('links', $link->id, $link->label));
            if (null !== $link->inverseLabel && '' === trim($link->inverseLabel)) {
                $violations[] = new ConfigViolation("links.$link->id.inverseLabel", 'cannot be blank, leave it null for a link that reads the same from both sides.');
            }
        }

        foreach ($definition->permissions as $permission => $roleIds) {
            $case = Permission::tryFrom($permission);
            if (null === $case) {
                $violations[] = new ConfigViolation("permissions.$permission", 'unknown permission.');
            }
            array_push($violations, ...self::listedTwice("permissions.$permission", $roleIds));
            foreach (array_unique($roleIds) as $roleId) {
                if (!$definition->hasRole($roleId)) {
                    $violations[] = new ConfigViolation("permissions.$permission", \sprintf('unknown role "%s".', $roleId));
                } elseif ($case?->internalOnly() && !$definition->role($roleId)->internalOnly) {
                    $violations[] = new ConfigViolation("permissions.$permission", \sprintf('role "%s" is not internal-only; this permission opens what external users never see.', $roleId));
                }
            }
        }

        foreach ($definition->workflows as $workflow) {
            array_push($violations, ...$this->validateWorkflow($definition, $workflow));
        }

        return $violations;
    }

    /**
     * @return list<ConfigViolation>
     */
    private function validateWorkflow(InstanceDefinition $definition, WorkflowDefinition $workflow): array
    {
        $path = "workflows.$workflow->id";
        $violations = self::named('workflows', $workflow->id, $workflow->label);

        if (!$workflow->hasPlace($workflow->initial)) {
            $violations[] = new ConfigViolation("$path.initial", \sprintf('unknown place "%s".', $workflow->initial));
        }
        array_push($violations, ...self::listedTwice("$path.places", $workflow->places));
        foreach (array_unique($workflow->places) as $placeId) {
            if (!$definition->hasPlace($placeId)) {
                $violations[] = new ConfigViolation("$path.places", \sprintf('unknown place "%s".', $placeId));
            }
        }

        $pairs = [];
        foreach ($workflow->transitions as $transition) {
            $transitionPath = "$path.transitions.$transition->id";
            array_push($violations, ...self::named("$path.transitions", $transition->id, $transition->label), ...self::validateTransition($transitionPath, $transition));

            foreach (array_unique($transition->from) as $from) {
                if (!$workflow->hasPlace($from)) {
                    $violations[] = new ConfigViolation("$transitionPath.from", \sprintf('unknown place "%s".', $from));
                }
                if (isset($pairs[$from][$transition->to])) {
                    $violations[] = new ConfigViolation($transitionPath, \sprintf('transition "%s" already goes from "%s" to "%s"; the kanban needs a single transition per pair of places.', $pairs[$from][$transition->to], $from, $transition->to));
                }
                $pairs[$from][$transition->to] = $transition->id;
            }
            if (!$workflow->hasPlace($transition->to)) {
                $violations[] = new ConfigViolation("$transitionPath.to", \sprintf('unknown place "%s".', $transition->to));
            }
            foreach (array_unique($transition->roles) as $roleId) {
                if (!$definition->hasRole($roleId)) {
                    $violations[] = new ConfigViolation("$transitionPath.roles", \sprintf('unknown role "%s".', $roleId));
                }
            }
        }

        foreach ($workflow->fields as $usage) {
            if (!$definition->hasField($usage->field)) {
                $violations[] = new ConfigViolation("$path.fields", \sprintf('unknown field "%s".', $usage->field));
            }
        }

        return $violations;
    }

    /**
     * An action starts from somewhere, someone triggers it, and what it
     * says of its comment and of itself is said or left out.
     *
     * @return list<ConfigViolation>
     */
    private static function validateTransition(string $path, TransitionDefinition $transition): array
    {
        $violations = [];
        if ([] === $transition->from) {
            $violations[] = new ConfigViolation("$path.from", 'at least one source place is required.');
        }
        if ([] === $transition->roles) {
            $violations[] = new ConfigViolation("$path.roles", 'at least one role is required.');
        }
        array_push($violations, ...self::listedTwice("$path.from", $transition->from), ...self::listedTwice("$path.roles", $transition->roles));
        if (null !== $transition->commentPrompt && !$transition->requiresComment) {
            $violations[] = new ConfigViolation("$path.commentPrompt", 'only makes sense when a comment is required.');
        }
        if (null !== $transition->description && '' === trim($transition->description)) {
            $violations[] = new ConfigViolation("$path.description", 'cannot be blank, leave it null instead.');
        }

        return $violations;
    }

    /**
     * A field that offers choices has some, each with a value and a label;
     * the other types have none.
     *
     * @return list<ConfigViolation>
     */
    private static function validateChoices(FieldDefinition $field): array
    {
        $path = "fields.$field->id.options";
        if (!$field->type->hasOptions()) {
            return [] === $field->options ? [] : [new ConfigViolation($path, \sprintf('a %s field cannot have options.', $field->type->value))];
        }
        if ([] === $field->options) {
            return [new ConfigViolation($path, \sprintf('a %s field needs options.', $field->type->value))];
        }
        foreach ($field->options as $value => $label) {
            // A value made of digits comes back from PHP as an integer key: it is a value like another.
            if ('' === trim((string) $value) || '' === trim($label)) {
                return [new ConfigViolation($path, 'options map a value to a non-empty label.')];
            }
        }

        return [];
    }

    /**
     * What is wrong with the key and the label every element has.
     *
     * @param string $collection where the element is listed (`roles`, `workflows.bug.transitions`)
     *
     * @return list<ConfigViolation>
     */
    private static function named(string $collection, string $id, string $label): array
    {
        $violations = [];
        if (1 !== preg_match(self::KEY, $id)) {
            $violations[] = new ConfigViolation($collection, \sprintf('"%s" is not a valid identifier (lowercase letters, digits and underscores, starting with a letter).', $id));
        }
        if ('' === trim($label)) {
            $violations[] = new ConfigViolation("$collection.$id.label", 'a label is required.');
        }

        return $violations;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ConfigViolation> one for each id a list names more than once
     */
    private static function listedTwice(string $path, array $ids): array
    {
        $violations = [];
        foreach (array_count_values($ids) as $id => $times) {
            if ($times > 1) {
                $violations[] = new ConfigViolation($path, \sprintf('"%s" is listed twice.', $id));
            }
        }

        return $violations;
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Security\Permission;

/**
 * Cross-validation of a definition: every reference points to something
 * that exists, and the structure can be run by the workflow engine and the
 * kanban. Local consistency is enforced by the definition constructors.
 */
final class ConfigValidator
{
    /**
     * @return list<ConfigViolation>
     */
    public function validate(InstanceDefinition $definition): array
    {
        $violations = [];

        foreach ($definition->permissions as $permission => $roleIds) {
            $case = Permission::tryFrom($permission);
            if (null === $case) {
                $violations[] = new ConfigViolation("permissions.$permission", 'unknown permission.');
            }
            foreach ($roleIds as $roleId) {
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
        $violations = [];

        if (!$workflow->hasPlace($workflow->initial)) {
            $violations[] = new ConfigViolation("$path.initial", \sprintf('unknown place "%s".', $workflow->initial));
        }
        if (null !== $workflow->fallbackPlace && !$workflow->hasPlace($workflow->fallbackPlace)) {
            $violations[] = new ConfigViolation("$path.fallbackPlace", \sprintf('unknown place "%s".', $workflow->fallbackPlace));
        }
        if (MigrationPolicy::Fallback === $definition->migrationPolicy && null === $workflow->fallbackPlace) {
            $violations[] = new ConfigViolation("$path.fallbackPlace", 'required under the Fallback migration policy.');
        }

        foreach ($workflow->places as $placeId) {
            if (!$definition->hasPlace($placeId)) {
                $violations[] = new ConfigViolation("$path.places", \sprintf('unknown place "%s".', $placeId));
            }
        }

        foreach ($workflow->migrations as $from => $to) {
            if ($workflow->hasPlace($from)) {
                $violations[] = new ConfigViolation("$path.migrations.$from", 'is still a place of this workflow.');
            }
            if (!$workflow->hasPlace($to)) {
                $violations[] = new ConfigViolation("$path.migrations.$from", \sprintf('"%s" is not a place of this workflow.', $to));
            }
        }

        $pairs = [];
        foreach ($workflow->transitions as $transition) {
            $transitionPath = "$path.transitions.$transition->id";
            foreach ($transition->from as $from) {
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
            foreach ($transition->roles as $roleId) {
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
}

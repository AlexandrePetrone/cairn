<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Field;
use App\Entity\LinkType;
use App\Entity\Place;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\Transition;
use App\Entity\TransitionRole;
use App\Entity\TransitionSource;
use App\Entity\Workflow;
use App\Entity\WorkflowField;
use App\Entity\WorkflowPlace;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the configuration tables into the immutable definition the
 * application reasons on. The one place that knows how the rows fit
 * together: everything else asks ConfigRegistry for the definition.
 *
 * It judges nothing: the definition says what the rows say. The commands
 * that write them keep them sound; a row written otherwise (a migration,
 * SQL by hand) is read as it is, so that the administration still opens
 * to put it right.
 */
final class DefinitionLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function load(): InstanceDefinition
    {
        $roles = $this->ordered(Role::class);

        $granted = [];
        foreach ($this->entityManager->getRepository(RolePermission::class)->findAll() as $grant) {
            $granted[$grant->getPermission()->value][$grant->getRole()->getId()] = true;
        }
        // In the order of the enum, each with its roles from the broadest to the narrowest.
        $permissions = [];
        foreach (Permission::cases() as $permission) {
            $holders = array_values(array_filter(
                array_map(static fn (Role $role): string => $role->getId(), $roles),
                static fn (string $roleId): bool => isset($granted[$permission->value][$roleId]),
            ));
            if ([] !== $holders) {
                $permissions[$permission->value] = $holders;
            }
        }

        $places = [];
        $aside = [];
        foreach ($this->entityManager->getRepository(WorkflowPlace::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']) as $used) {
            $places[$used->getWorkflow()->getId()][] = $used->getPlace()->getId();
            if (null !== $used->getBeside()) {
                $aside[$used->getWorkflow()->getId()][$used->getPlace()->getId()] = $used->getBeside()->getPlace()->getId();
            }
        }
        $fields = [];
        foreach ($this->entityManager->getRepository(WorkflowField::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']) as $used) {
            $fields[$used->getWorkflow()->getId()][] = new FieldUsage($used->getField()->getId(), $used->isRequired());
        }
        $sources = [];
        foreach ($this->entityManager->getRepository(TransitionSource::class)->findBy([], ['id' => 'ASC']) as $source) {
            $sources[(int) $source->getTransition()->getId()][] = $source->getPlace()->getId();
        }
        $allowed = [];
        foreach ($this->entityManager->getRepository(TransitionRole::class)->findBy([], ['id' => 'ASC']) as $role) {
            $allowed[(int) $role->getTransition()->getId()][] = $role->getRole()->getId();
        }
        $transitions = [];
        foreach ($this->entityManager->getRepository(Transition::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']) as $transition) {
            $id = (int) $transition->getId();
            $transitions[$transition->getWorkflow()->getId()][] = new TransitionDefinition(
                id: $transition->getKey(),
                label: $transition->getLabel(),
                from: $sources[$id] ?? [],
                to: $transition->getTarget()->getId(),
                roles: $allowed[$id] ?? [],
                style: $transition->getStyle(),
                requiresComment: $transition->requiresComment(),
                commentPrompt: $transition->getCommentPrompt(),
                description: $transition->getDescription(),
            );
        }

        return new InstanceDefinition(
            roles: array_map(static fn (Role $role): RoleDefinition => new RoleDefinition($role->getId(), $role->getLabel(), $role->isInternalOnly(), $role->followsNewIssues(), $role->hasFreeWorkflow()), $roles),
            permissions: $permissions,
            fields: array_map(static fn (Field $field): FieldDefinition => new FieldDefinition($field->getId(), $field->getLabel(), $field->getType(), $field->getOptions(), $field->getPlaceholder(), $field->getHelp(), $field->isInternalOnly()), $this->ordered(Field::class)),
            links: array_map(static fn (LinkType $link): LinkTypeDefinition => new LinkTypeDefinition($link->getId(), $link->getLabel(), $link->getInverseLabel()), $this->ordered(LinkType::class)),
            places: array_map(static fn (Place $place): PlaceDefinition => new PlaceDefinition($place->getId(), $place->getLabel(), $place->getStatus()), $this->ordered(Place::class)),
            workflows: array_map(static fn (Workflow $workflow): WorkflowDefinition => new WorkflowDefinition(
                id: $workflow->getId(),
                label: $workflow->getLabel(),
                initial: $workflow->getInitial()->getId(),
                places: $places[$workflow->getId()] ?? [],
                transitions: $transitions[$workflow->getId()] ?? [],
                fields: $fields[$workflow->getId()] ?? [],
                aside: $aside[$workflow->getId()] ?? [],
            ), $this->ordered(Workflow::class)),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T> in the order people gave them
     */
    private function ordered(string $class): array
    {
        return $this->entityManager->getRepository($class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }
}

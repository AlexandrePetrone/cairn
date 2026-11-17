<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\ConfigValidator;
use App\Config\DefinitionLoader;
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
use App\Message\Command\Config\InstallConfiguration;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes a whole definition into the empty tables, in the order it gives
 * its roles, places, fields, links and workflows. The definition is
 * checked as a whole first, so that what is installed is what the
 * commands that change it afterwards would have accepted.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class InstallConfigurationHandler
{
    public function __construct(
        private readonly DefinitionLoader $loader,
        private readonly ConfigValidator $validator,
        private readonly ConfigRegistry $registry,
        private readonly ConfigJournal $journal,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(InstallConfiguration $command): void
    {
        if (!$this->loader->load()->isEmpty()) {
            throw new ConfigException('The instance already has a configuration; it changes from the administration.');
        }

        $definition = $command->definition;
        $violations = $this->validator->validate($definition);
        if ([] !== $violations) {
            throw new ConfigException('The configuration to install is invalid: '.implode(' ', array_map(strval(...), $violations)));
        }

        $roles = [];
        foreach (array_values($definition->roles) as $position => $role) {
            $this->entityManager->persist($roles[$role->id] = new Role($role->id, $role->label, $role->internalOnly, $role->followsNewIssues, $position));
        }
        foreach ($definition->permissions as $permission => $roleIds) {
            foreach ($roleIds as $roleId) {
                $this->entityManager->persist(new RolePermission($roles[$roleId], Permission::from($permission)));
            }
        }

        $places = [];
        foreach (array_values($definition->places) as $position => $place) {
            $this->entityManager->persist($places[$place->id] = new Place($place->id, $place->label, $place->status, $position));
        }
        $fields = [];
        foreach (array_values($definition->fields) as $position => $field) {
            $this->entityManager->persist($fields[$field->id] = new Field($field->id, $field->label, $field->type, $field->options, $field->placeholder, $field->help, $field->internalOnly, $position));
        }
        foreach (array_values($definition->links) as $position => $link) {
            $this->entityManager->persist(new LinkType($link->id, $link->label, $link->inverseLabel, $position));
        }

        foreach (array_values($definition->workflows) as $position => $definedWorkflow) {
            $workflow = new Workflow($definedWorkflow->id, $definedWorkflow->label, $places[$definedWorkflow->initial], $position);
            $this->entityManager->persist($workflow);

            foreach ($definedWorkflow->places as $placePosition => $placeId) {
                $this->entityManager->persist(new WorkflowPlace($workflow, $places[$placeId], $placePosition));
            }
            foreach (array_values($definedWorkflow->fields) as $fieldPosition => $usage) {
                $this->entityManager->persist(new WorkflowField($workflow, $fields[$usage->field], $usage->required, $fieldPosition));
            }
            foreach (array_values($definedWorkflow->transitions) as $transitionPosition => $defined) {
                $transition = new Transition($workflow, $defined->id, $defined->label, $places[$defined->to], $defined->style, $defined->requiresComment, $defined->commentPrompt, $defined->description, $transitionPosition);
                $this->entityManager->persist($transition);
                foreach ($defined->from as $placeId) {
                    $this->entityManager->persist(new TransitionSource($transition, $places[$placeId]));
                }
                foreach ($defined->roles as $roleId) {
                    $this->entityManager->persist(new TransitionRole($transition, $roles[$roleId]));
                }
            }
        }

        $this->journal->record('installed', [
            '%roles%' => \count($definition->roles),
            '%workflows%' => \count($definition->workflows),
            '%places%' => \count($definition->places),
            '%fields%' => \count($definition->fields),
            '%links%' => \count($definition->links),
        ]);

        // Whoever reads the configuration after this command reads the tables again.
        $this->registry->reset();
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\ConfigValidator;
use App\Config\DefinitionLoader;
use App\Entity\ConfigChange;
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
use App\Repository\TransitionRepository;
use App\Repository\WorkflowRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the handlers that change the configuration do alike: find what a
 * command names, give a new element its key, and settle the change.
 *
 * There is no draft: a change is in force as soon as its command ends, so
 * every command must leave a configuration the application can run.
 * settle() is what guarantees it, whatever the handler checked itself: it
 * writes the change, reads the whole configuration again and refuses the
 * command when the ConfigValidator finds a fault.
 */
final class ConfigWriting
{
    private const int KEY_LENGTH = 60;

    private const array ENTITIES = [
        Role::class, RolePermission::class, Place::class, Field::class, LinkType::class, Workflow::class,
        WorkflowPlace::class, WorkflowField::class, Transition::class, TransitionSource::class, TransitionRole::class, ConfigChange::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflows,
        private readonly TransitionRepository $transitions,
        private readonly DefinitionLoader $loader,
        private readonly ConfigValidator $validator,
        private readonly ConfigRegistry $registry,
        private readonly ConfigJournal $journal,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function get(string $class, string $id): object
    {
        return $this->entityManager->find($class, $id) ?? throw ConfigChangeException::notFound(substr((string) strrchr($class, '\\'), 1), $id);
    }

    public function transition(Workflow $workflow, string $key): Transition
    {
        return $this->transitions->findOne($workflow, $key) ?? throw ConfigChangeException::notFound('Transition', $workflow->getId().'.'.$key);
    }

    /**
     * The key of a new element: the one given, when it is well formed and
     * free, otherwise one made from the label ("À recetter" gives
     * `a_recetter`, then `a_recetter_2`). A key is never changed afterwards.
     *
     * @param \Closure(string): bool $taken
     */
    public function key(?string $given, string $label, \Closure $taken): string
    {
        if (null !== $given) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $given) || \strlen($given) > self::KEY_LENGTH) {
                throw ConfigChangeException::invalidKey($given);
            }
            if ($taken($given)) {
                throw ConfigChangeException::keyTaken($given);
            }

            return $given;
        }

        $base = (new AsciiSlugger())->slug($label, '_')->lower()->toString();
        $base = (string) preg_replace('/[^a-z0-9_]/', '', $base);
        if (1 !== preg_match('/^[a-z]/', $base)) {
            $base = 'x_'.$base;
        }
        $base = rtrim(substr($base, 0, self::KEY_LENGTH - 4), '_');

        $key = $base;
        for ($suffix = 2; $taken($key); ++$suffix) {
            $key = $base.'_'.$suffix;
        }

        return $key;
    }

    /**
     * The place, which must be one of the workflow.
     */
    public function placeOf(Workflow $workflow, string $placeId): Place
    {
        $place = $this->get(Place::class, $placeId);
        if (null === $this->workflows->findPlace($workflow, $place)) {
            throw ConfigChangeException::notAPlaceOf($place->getLabel(), $workflow->getLabel());
        }

        return $place;
    }

    /**
     * Gives an action the places it may be triggered from: those it no
     * longer has go, those it gains are added, the others are left alone.
     *
     * @param list<string> $placeIds places of the workflow of the action
     */
    public function triggerFrom(Transition $transition, array $placeIds): void
    {
        $wanted = array_flip($placeIds);
        foreach ($this->transitions->sourcesOf($transition) as $source) {
            if (isset($wanted[$source->getPlace()->getId()])) {
                unset($wanted[$source->getPlace()->getId()]);
            } else {
                $this->entityManager->remove($source);
            }
        }
        foreach (array_keys($wanted) as $placeId) {
            $this->entityManager->persist(new TransitionSource($transition, $this->placeOf($transition->getWorkflow(), $placeId)));
        }
    }

    /**
     * Gives an action the roles that may trigger it, the same way.
     *
     * @param list<string> $roleIds
     */
    public function allow(Transition $transition, array $roleIds): void
    {
        $wanted = array_flip($roleIds);
        foreach ($this->transitions->rolesOf($transition) as $allowed) {
            if (isset($wanted[$allowed->getRole()->getId()])) {
                unset($wanted[$allowed->getRole()->getId()]);
            } else {
                $this->entityManager->remove($allowed);
            }
        }
        foreach (array_keys($wanted) as $roleId) {
            $this->entityManager->persist(new TransitionRole($transition, $this->get(Role::class, $roleId)));
        }
    }

    /**
     * The kanban needs a single action per pair of places: refuses the
     * ends an action would share with another of its workflow.
     *
     * @param list<string> $from
     */
    public function assertPairsFree(Workflow $workflow, ?string $key, array $from, string $to): void
    {
        $definition = $this->registry->definition();
        foreach ($definition->workflow($workflow->getId())->transitions as $other) {
            if ($other->id === $key || $other->to !== $to) {
                continue;
            }
            foreach (array_intersect($from, $other->from) as $shared) {
                throw ConfigChangeException::pairTaken($other->label, $definition->place($shared)->label, $definition->place($to)->label);
            }
        }
    }

    /**
     * How a permission is named in the journal: as the administration names it.
     */
    public function permissionLabel(Permission $permission): string
    {
        return $this->translator->trans('permission.'.$permission->value.'.label');
    }

    /**
     * Removes an action with the rows that say where it starts from and
     * who triggers it. The database would cascade, but the entity manager
     * would go on holding rows that name an action it no longer knows.
     */
    public function removeTransition(Transition $transition): void
    {
        array_map($this->entityManager->remove(...), $this->transitions->sourcesOf($transition));
        array_map($this->entityManager->remove(...), $this->transitions->rolesOf($transition));
        $this->entityManager->remove($transition);
    }

    /**
     * Ends a handler: the change is written, with a line of the journal
     * saying what it was (`admin.configuration.history.<event>`), and the
     * configuration it makes is read whole and checked. A fault refuses
     * the command, whose transaction is rolled back.
     *
     * @param array<string, string|int> $parameters what the sentence of the journal names
     */
    public function settle(string $event, array $parameters = []): void
    {
        $this->journal->record($event, $parameters);
        $this->entityManager->flush();
        $this->registry->reset();

        try {
            $violations = $this->validator->validate($this->loader->load());
        } catch (ConfigException $e) {
            $this->forget();
            throw ConfigChangeException::invalid($e->getPrevious()?->getMessage() ?? $e->getMessage());
        }
        if ([] !== $violations) {
            $this->forget();
            throw ConfigChangeException::invalid(implode(' ', array_map(strval(...), $violations)));
        }
    }

    /**
     * The transaction of a refused command is rolled back, not what the
     * entity manager holds: the rows it wrote are forgotten, so that
     * nothing later in the request reads them or writes them again.
     */
    private function forget(): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            if (\in_array($class, self::ENTITIES, true)) {
                array_map($this->entityManager->detach(...), $entities);
            }
        }
        $this->registry->reset();
    }
}

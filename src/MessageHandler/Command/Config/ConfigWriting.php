<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Config\ConfigRegistry;
use App\Config\FieldType;
use App\Entity\Place;
use App\Entity\Role;
use App\Entity\Transition;
use App\Entity\TransitionRole;
use App\Entity\TransitionSource;
use App\Entity\Workflow;
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
 * every command must leave a configuration the application can run. Each
 * handler checks what it is asked against the rules before it writes
 * anything, as every handler of the application does; nothing is checked
 * afterwards. The tests are what holds them to it: after every command
 * they accept, the ConfigValidator must find the configuration sound.
 */
final class ConfigWriting
{
    private const int KEY_LENGTH = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflows,
        private readonly TransitionRepository $transitions,
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
     * A field that offers choices has some, each with a value and a label;
     * the other types have none.
     *
     * @param array<array-key, string> $options value => label
     */
    public static function assertChoices(FieldType $type, array $options): void
    {
        if (!$type->hasOptions()) {
            if ([] !== $options) {
                throw ConfigChangeException::choicesNotOffered();
            }

            return;
        }
        if ([] === $options) {
            throw ConfigChangeException::choicesRequired();
        }
        foreach ($options as $value => $label) {
            if ('' === trim((string) $value) || '' === trim($label)) {
                throw ConfigChangeException::choicesRequired();
            }
        }
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
     * Ends a handler: a line of the journal says what the change was
     * (`admin.configuration.history.<event>`), and whoever reads the
     * configuration after the command reads the tables again.
     *
     * @param array<string, string|int> $parameters what the sentence of the journal names
     */
    public function settle(string $event, array $parameters = []): void
    {
        $this->journal->record($event, $parameters);
        $this->registry->reset();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\Transition;
use App\Entity\TransitionRole;
use App\Entity\TransitionSource;
use App\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transition>
 */
class TransitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transition::class);
    }

    public function findOne(Workflow $workflow, string $key): ?Transition
    {
        return $this->findOneBy(['workflow' => $workflow, 'key' => $key]);
    }

    /**
     * @return list<Transition> the actions of a workflow, in their order
     */
    public function findByWorkflow(Workflow $workflow): array
    {
        return $this->findBy(['workflow' => $workflow], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<TransitionSource> the places the action may be triggered from
     */
    public function sourcesOf(Transition $transition): array
    {
        return $this->getEntityManager()->getRepository(TransitionSource::class)->findBy(['transition' => $transition], ['id' => 'ASC']);
    }

    /**
     * @return list<TransitionRole> the roles that may trigger the action
     */
    public function rolesOf(Transition $transition): array
    {
        return $this->getEntityManager()->getRepository(TransitionRole::class)->findBy(['transition' => $transition], ['id' => 'ASC']);
    }

    /**
     * @return list<Transition> the actions, of any workflow, the role may trigger
     */
    public function findNamingRole(Role $role): array
    {
        return array_map(static fn (TransitionRole $allowed): Transition => $allowed->getTransition(), $this->getEntityManager()->getRepository(TransitionRole::class)->findBy(['role' => $role]));
    }
}

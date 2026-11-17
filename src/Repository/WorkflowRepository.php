<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Field;
use App\Entity\Place;
use App\Entity\Workflow;
use App\Entity\WorkflowField;
use App\Entity\WorkflowPlace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workflow>
 */
class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workflow::class);
    }

    /**
     * @return list<Workflow> in the order people gave them
     */
    public function findOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<WorkflowPlace> the places a workflow uses, in the order of its kanban
     */
    public function placesOf(Workflow $workflow): array
    {
        return $this->getEntityManager()->getRepository(WorkflowPlace::class)->findBy(['workflow' => $workflow], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findPlace(Workflow $workflow, Place $place): ?WorkflowPlace
    {
        return $this->getEntityManager()->getRepository(WorkflowPlace::class)->findOneBy(['workflow' => $workflow, 'place' => $place]);
    }

    /**
     * @return list<WorkflowField> the fields a workflow asks for, in the order of its form
     */
    public function fieldsOf(Workflow $workflow): array
    {
        return $this->getEntityManager()->getRepository(WorkflowField::class)->findBy(['workflow' => $workflow], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<string> labels of the workflows that use the place
     */
    public function labelsUsingPlace(Place $place): array
    {
        return array_map(static fn (WorkflowPlace $used): string => $used->getWorkflow()->getLabel(), $this->getEntityManager()->getRepository(WorkflowPlace::class)->findBy(['place' => $place]));
    }

    /**
     * @return list<string> labels of the workflows that ask for the field
     */
    public function labelsUsingField(Field $field): array
    {
        return array_map(static fn (WorkflowField $used): string => $used->getWorkflow()->getLabel(), $this->getEntityManager()->getRepository(WorkflowField::class)->findBy(['field' => $field]));
    }
}

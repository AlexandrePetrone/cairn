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
     * @return list<string> labels of the workflows that use the place, in the order of the instance
     */
    public function labelsUsingPlace(Place $place): array
    {
        return self::labels($this->getEntityManager()->getRepository(WorkflowPlace::class)->findBy(['place' => $place]));
    }

    /**
     * @return list<string> labels of the workflows that ask for the field, in the order of the instance
     */
    public function labelsUsingField(Field $field): array
    {
        return self::labels($this->getEntityManager()->getRepository(WorkflowField::class)->findBy(['field' => $field]));
    }

    /**
     * The labels of the workflows some rows name, in the order people gave
     * the workflows: the database gives the rows in no order of its own.
     *
     * @param list<WorkflowPlace|WorkflowField> $used
     *
     * @return list<string>
     */
    private static function labels(array $used): array
    {
        $workflows = array_map(static fn (WorkflowPlace|WorkflowField $each): Workflow => $each->getWorkflow(), $used);
        usort($workflows, static fn (Workflow $a, Workflow $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return array_map(static fn (Workflow $workflow): string => $workflow->getLabel(), $workflows);
    }
}

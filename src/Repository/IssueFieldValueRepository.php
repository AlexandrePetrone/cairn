<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueFieldValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueFieldValue>
 */
class IssueFieldValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueFieldValue::class);
    }

    /**
     * What an issue holds, whoever looks: the fields its workflow no longer
     * asks for included, those never shown to external users too. What a
     * reader sees of it is InstanceDefinition::fieldValues()'s to say.
     *
     * @return array<string, mixed> field id => value
     */
    public function valuesOf(Issue $issue): array
    {
        $values = [];
        foreach ($this->rowsOf($issue) as $held) {
            $values[$held->getFieldId()] = $held->getValue();
        }

        return $values;
    }

    /**
     * @return array<string, IssueFieldValue> field id => the row; none for an issue not written yet
     */
    public function rowsOf(Issue $issue): array
    {
        $rows = [];
        foreach (null === $issue->getId() ? [] : $this->findBy(['issue' => $issue], ['id' => 'ASC']) as $held) {
            $rows[$held->getFieldId()] = $held;
        }

        return $rows;
    }

    /**
     * How many issues hold a value of a field: what goes with the field.
     */
    public function countHolding(string $fieldId): int
    {
        return $this->count(['field' => $fieldId]);
    }

    /**
     * Erases every value of a field, in bulk: no entity is loaded, and the
     * issues are left alone, since a field that leaves is not an activity
     * on them.
     *
     * @return int number of values erased
     */
    public function eraseOf(string $fieldId): int
    {
        $erased = (int) $this->createQueryBuilder('v')
            ->delete()
            ->where('v.field = :field')
            ->setParameter('field', $fieldId)
            ->getQuery()
            ->execute();

        // A bulk statement tells the entity manager nothing: the values it holds of that field would go on naming a field that left.
        foreach ($this->getEntityManager()->getUnitOfWork()->getIdentityMap()[IssueFieldValue::class] ?? [] as $held) {
            if ($held instanceof IssueFieldValue && $held->getFieldId() === $fieldId) {
                $this->getEntityManager()->detach($held);
            }
        }

        return $erased;
    }
}

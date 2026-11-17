<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutomationRule>
 */
class AutomationRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutomationRule::class);
    }

    /**
     * The rules of a project in the order they were written, which is the
     * order they run in.
     *
     * @return list<AutomationRule>
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['id' => 'ASC']);
    }

    /**
     * @return list<AutomationRule>
     */
    public function findActive(Project $project): array
    {
        return $this->findBy(['project' => $project, 'active' => true], ['id' => 'ASC']);
    }

    /**
     * The active rules of every project that is not archived, with their
     * project: what the rules that wait for time are picked from.
     *
     * @return list<AutomationRule>
     */
    public function findAllActive(): array
    {
        $query = $this->createQueryBuilder('r')
            ->addSelect('p', 'w')
            ->join('r.project', 'p')
            ->join('p.workspace', 'w')
            ->andWhere('r.active = true')
            ->orderBy('r.id', 'ASC')
            ->getQuery();

        return EntityList::of($query, AutomationRule::class);
    }

    /**
     * The open issues a rule that waits for time is due on: those of its
     * project (of its tracker when it is limited to one) nobody acted on
     * since a date, on which it did not already run since someone last
     * did. By id, after `$afterId`, to walk them in batches.
     *
     * @return list<Issue>
     */
    public function findDueIssues(AutomationRule $rule, \DateTimeImmutable $before, int $afterId, int $limit): array
    {
        return EntityList::of($this->due($rule, $before)->andWhere('i.id > :after')->setParameter('after', $afterId)->orderBy('i.id', 'ASC')->setMaxResults($limit)->getQuery(), Issue::class);
    }

    /**
     * Whether the rule is due on this issue, by the same rule as the
     * batch: asked again just before it runs, things moved since.
     */
    public function isDueOn(AutomationRule $rule, \DateTimeImmutable $before, Issue $issue): bool
    {
        return [] !== $this->due($rule, $before)->select('i.id')->andWhere('i = :issue')->setParameter('issue', $issue)->getQuery()->getResult();
    }

    private function due(AutomationRule $rule, \DateTimeImmutable $before): QueryBuilder
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('i')
            ->from(Issue::class, 'i')
            ->join('i.place', 'step')
            ->andWhere('i.project = :project')
            ->andWhere('step.status != :closed')
            ->andWhere('COALESCE(i.lastActivityAt, i.updatedAt) <= :before')
            ->andWhere('NOT EXISTS (SELECT n.id FROM '.AutomationRun::class.' n WHERE n.rule = :rule AND n.issue = i AND n.at >= COALESCE(i.lastActivityAt, i.updatedAt))')
            ->setParameter('project', $rule->getProject())
            ->setParameter('closed', PlaceStatus::Closed)
            ->setParameter('before', $before)
            ->setParameter('rule', $rule);
        if (null !== $rule->getTracker()) {
            $queryBuilder->andWhere('i.tracker = :tracker')->setParameter('tracker', $rule->getTracker());
        }

        return $queryBuilder;
    }
}

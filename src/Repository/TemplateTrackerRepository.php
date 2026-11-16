<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectTemplate;
use App\Entity\TemplateTracker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TemplateTracker>
 */
class TemplateTrackerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemplateTracker::class);
    }

    /**
     * @return list<TemplateTracker> the trackers of a template, in their order
     */
    public function findByTemplate(ProjectTemplate $template): array
    {
        return $this->findBy(['template' => $template], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return array<int, int> template id => trackers
     */
    public function countByTemplate(): array
    {
        /** @var list<array{template: int|string, trackers: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.template) AS template', 'COUNT(t.id) AS trackers')
            ->groupBy('t.template')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [(int) $row['template'], (int) $row['trackers']], $rows), 1, 0);
    }

    /**
     * The templates that use each workflow, for the configuration sync:
     * a workflow a template still names stays in the instance file.
     *
     * @return array<string, list<string>> workflow id => names of the templates
     */
    public function templatesByWorkflow(): array
    {
        /** @var list<array{workflowId: string, name: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.workflowId AS workflowId', 'p.name AS name')
            ->distinct()
            ->join('t.template', 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $templates = [];
        foreach ($rows as $row) {
            $templates[$row['workflowId']][] = $row['name'];
        }

        return $templates;
    }
}

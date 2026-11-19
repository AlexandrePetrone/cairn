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
     * The templates that have a tracker on a workflow: a workflow a template
     * still names cannot be deleted.
     *
     * @return list<string> the names of the templates
     */
    public function templatesUsing(string $workflowId): array
    {
        $names = $this->createQueryBuilder('t')
            ->select('p.name')
            ->distinct()
            ->join('t.template', 'p')
            ->where('t.workflow = :workflow')
            ->setParameter('workflow', $workflowId)
            ->orderBy('p.name', \SortDirection::Ascending)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $name): string => \is_string($name) ? $name : '', $names));
    }
}

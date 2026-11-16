<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectTemplate;
use App\Entity\TemplateRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TemplateRule>
 */
class TemplateRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemplateRule::class);
    }

    /**
     * The rules of a template in the order they were written, which is
     * the order they are copied in, hence the one they run in.
     *
     * @return list<TemplateRule>
     */
    public function findByTemplate(ProjectTemplate $template): array
    {
        return $this->findBy(['template' => $template], ['id' => 'ASC']);
    }

    /**
     * @return array<int, int> template id => rules
     */
    public function countByTemplate(): array
    {
        /** @var list<array{template: int|string, rules: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.template) AS template', 'COUNT(r.id) AS rules')
            ->groupBy('r.template')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [(int) $row['template'], (int) $row['rules']], $rows), 1, 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueTag;
use App\Entity\Project;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueTag>
 */
class IssueTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueTag::class);
    }

    /**
     * The tags an issue carries, by name.
     *
     * @return list<Tag>
     */
    public function tagsOf(Issue $issue): array
    {
        return $this->tagsByIssue([$issue])[(int) $issue->getId()] ?? [];
    }

    /**
     * The tags of several issues at once, for a page of a list: one read
     * whatever their number.
     *
     * @param iterable<Issue> $issues
     *
     * @return array<int, list<Tag>> issue id => its tags by name; an issue without any is left out
     */
    public function tagsByIssue(iterable $issues): array
    {
        $ids = [];
        foreach ($issues as $issue) {
            $ids[] = (int) $issue->getId();
        }
        if ([] === $ids) {
            return [];
        }

        /** @var list<IssueTag> $rows */
        $rows = $this->createQueryBuilder('it')
            ->addSelect('tag')
            ->join('it.tag', 'tag')
            ->where('it.issue IN (:issues)')
            ->setParameter('issues', $ids)
            ->getQuery()
            ->getResult();

        $tags = [];
        foreach ($rows as $row) {
            $tags[(int) $row->getIssue()->getId()][] = $row->getTag();
        }

        return array_map(TagRepository::sorted(...), $tags);
    }

    /**
     * How many issues carry each tag of a project, closed ones included.
     *
     * @return array<int, int> tag id => issues; a tag no issue carries is left out
     */
    public function countByTag(Project $project): array
    {
        /** @var list<array{tagId: int|string, issues: int|string}> $rows */
        $rows = $this->createQueryBuilder('it')
            ->select('IDENTITY(it.tag) AS tagId', 'COUNT(it.id) AS issues')
            ->join('it.tag', 'tag')
            ->where('tag.project = :project')
            ->setParameter('project', $project)
            ->groupBy('it.tag')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [(int) $row['tagId'], (int) $row['issues']], $rows), 1, 0);
    }

    /**
     * Takes a tag off every issue that carries it, in one statement: the
     * tag is being deleted.
     */
    public function removeTag(Tag $tag): void
    {
        $this->createQueryBuilder('it')->delete()->where('it.tag = :tag')->setParameter('tag', $tag)->getQuery()->execute();
    }
}

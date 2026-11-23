<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * The tags of a project, by name.
     *
     * @return list<Tag>
     */
    public function findByProject(Project $project): array
    {
        return self::sorted($this->findBy(['project' => $project]));
    }

    /**
     * The tag of that name in the project, whatever its case: compared
     * here, among a handful, so that an accented letter is lowered the
     * same way on both sides whatever the collation of the database.
     */
    public function findOneByName(Project $project, string $name): ?Tag
    {
        $wanted = mb_strtolower(trim($name));

        return array_find($this->findBy(['project' => $project]), static fn (Tag $tag): bool => mb_strtolower($tag->getName()) === $wanted);
    }

    /**
     * By name, as people read them: a project holds a handful, sorted here
     * rather than in SQL, whose order depends on the collation.
     *
     * @param list<Tag> $tags
     *
     * @return list<Tag>
     */
    public static function sorted(array $tags): array
    {
        usort($tags, static fn (Tag $a, Tag $b): int => [mb_strtolower($a->getName()), $a->getId()] <=> [mb_strtolower($b->getName()), $b->getId()]);

        return $tags;
    }
}

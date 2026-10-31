<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Attachment>
 */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Attachment
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Attachments a user sent to a project that no issue claimed yet.
     *
     * @param list<Uuid> $uuids
     *
     * @return list<Attachment>
     */
    public function findUnattached(Project $project, User $uploadedBy, array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }

        return $this->findBy(['project' => $project, 'uploadedBy' => $uploadedBy, 'issue' => null, 'uuid' => $uuids]);
    }
}

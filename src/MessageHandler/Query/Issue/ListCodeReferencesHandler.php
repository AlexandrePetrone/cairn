<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Entity\CodeReference;
use App\Message\Query\Issue\ListCodeReferences;
use App\Repository\CodeReferenceRepository;
use App\Security\AccessControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListCodeReferencesHandler
{
    public function __construct(
        private readonly CodeReferenceRepository $references,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * @return list<CodeReference>
     */
    public function __invoke(ListCodeReferences $query): array
    {
        if (!$query->viewer->isInternal() || !$this->access->canSee($query->viewer, $query->issue)) {
            return [];
        }
        // The order rests on the enum values: `merge_request` before `commit`, then `opened`, `merged`, `closed`.
        $references = $this->references->findBy(['issue' => $query->issue], ['kind' => 'DESC', 'state' => 'DESC', 'updatedAt' => 'DESC', 'id' => 'DESC']);

        return $references;
    }
}

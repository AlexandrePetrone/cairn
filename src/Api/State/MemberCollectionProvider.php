<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\Refs;
use App\Api\Resource\MemberResource;
use App\Entity\User;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;

/**
 * @implements ProviderInterface<MemberResource>
 */
final class MemberCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Lookup $lookup,
        private readonly Refs $refs,
    ) {
    }

    /**
     * @return list<MemberResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $project = $this->lookup->project((string) $uriVariables['key']);

        return array_map(
            fn (User $user): MemberResource => MemberResource::of($this->refs->user($user)),
            $this->queries->ask(new ListProjectMembers($project)),
        );
    }
}

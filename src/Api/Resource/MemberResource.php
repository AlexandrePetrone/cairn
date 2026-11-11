<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\Resource\Value\UserRef;
use App\Api\State\MemberCollectionProvider;

/**
 * Someone with a role on a project: who an issue may be assigned to.
 */
#[ApiResource(
    shortName: 'Member',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{key}/members',
            uriVariables: ['key' => new Link(fromClass: ProjectResource::class)],
            description: 'The active people with a role on the project, by name.',
            paginationEnabled: false,
            provider: MemberCollectionProvider::class,
        ),
    ],
)]
final readonly class MemberResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $name,
        public bool $internal,
        /** For the agency only. */
        public ?string $email = null,
        public ?string $company = null,
    ) {
    }

    public static function of(UserRef $user): self
    {
        return new self($user->id, $user->name, $user->internal, $user->email, $user->company);
    }
}

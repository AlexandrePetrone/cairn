<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\State\MeProvider;
use App\Entity\ApiToken;
use App\Entity\User;

/**
 * Who the token speaks for, and what it may do: the first call of a
 * client checking its token.
 */
#[ApiResource(
    shortName: 'Me',
    operations: [new Get(uriTemplate: '/me', provider: MeProvider::class)],
)]
final readonly class MeResource
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $internal,
        public bool $admin,
        public ?string $company,
        /** What the token allows: `read`, or `write` which reads too. */
        public string $scope,
    ) {
    }

    public static function of(User $user, ApiToken $token): self
    {
        return new self((int) $user->getId(), $user->getFullName(), $user->getEmail(), $user->isInternal(), $user->isAdmin(), $user->getCompany(), $token->getScope()->value);
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\ApiToken;

use App\Entity\Enum\ApiTokenScope;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an API token for the actor, who must be internal. Handled by
 * CreateApiTokenHandler, which returns a NewApiToken: the token and its
 * secret, known at that moment only.
 */
final readonly class CreateApiToken
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 80)]
        public string $name,
        public ApiTokenScope $scope = ApiTokenScope::Read,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}

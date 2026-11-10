<?php

declare(strict_types=1);

namespace App\Message\Command\ApiToken;

use App\Entity\ApiToken;

/**
 * What creating a token gives back: the token, and the secret to hand to
 * its owner, which is stored nowhere.
 */
final readonly class NewApiToken
{
    public function __construct(
        public ApiToken $token,
        #[\SensitiveParameter]
        public string $secret,
    ) {
    }
}

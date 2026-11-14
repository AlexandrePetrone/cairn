<?php

declare(strict_types=1);

namespace App\Form\ApiToken;

use App\Entity\Enum\ApiTokenScope;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form creating an API token collects: a name to tell it from
 * the others, what it may do, and how long it works. The controller
 * turns it into CreateApiToken.
 */
final class ApiTokenData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    public ?string $name = null;

    public ApiTokenScope $scope = ApiTokenScope::Read;

    /** How long the token works: a key of LIFETIMES. */
    #[Assert\Choice(callback: 'lifetimes')]
    public string $lifetime = '90d';

    /** The lifetimes offered, in days; null for a token that only stops when revoked. */
    public const array LIFETIMES = ['30d' => 30, '90d' => 90, '365d' => 365, 'never' => null];

    /**
     * @return list<string>
     */
    public static function lifetimes(): array
    {
        return array_keys(self::LIFETIMES);
    }

    /**
     * When a token created at that moment expires, null when it does not.
     */
    public function expiresAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $days = self::LIFETIMES[$this->lifetime] ?? null;

        return null === $days ? null : $now->modify(\sprintf('+%d days', $days));
    }
}

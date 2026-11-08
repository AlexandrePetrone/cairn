<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where the code lives. A forge sends Cairn what happens to the
 * repositories linked to a project (pushes, merge requests). Persisted on
 * the linked repositories and on the code references they produced.
 */
enum Forge: string
{
    case GitLab = 'gitlab';
    case GitHub = 'github';

    /** The translation key of the name shown to users. */
    public function label(): string
    {
        return 'forge.'.$this->value;
    }

    /** A forge is offered once Cairn can parse its deliveries. */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::GitLab => true,
            self::GitHub => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $forge): bool => $forge->isAvailable()));
    }
}

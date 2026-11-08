<?php

declare(strict_types=1);

namespace App\Forge;

use App\Entity\Enum\Forge;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The API client of a forge.
 */
final class ForgeApiLocator
{
    /** @var array<string, ForgeApi> */
    private array $apis = [];

    /**
     * @param iterable<ForgeApi> $apis
     */
    public function __construct(#[AutowireIterator('app.forge_api')] iterable $apis)
    {
        foreach ($apis as $api) {
            $this->apis[$api->forge()->value] = $api;
        }
    }

    public function for(Forge $forge): ForgeApi
    {
        return $this->apis[$forge->value] ?? throw new \LogicException(\sprintf('No API client for the forge "%s".', $forge->value));
    }
}

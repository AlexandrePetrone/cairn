<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runtime access to the configuration: the tables, read once per request,
 * and per message for a worker, which resets its services between two
 * messages: no shared cache on purpose, so that a change made anywhere is
 * visible to every process at its next request or message. A command that
 * writes the configuration resets it for the rest of its own.
 */
final class ConfigRegistry implements ResetInterface
{
    private ?InstanceDefinition $definition = null;

    public function __construct(
        private readonly DefinitionLoader $loader,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * An instance nobody configured yet has an empty definition: no role,
     * no workflow, and pages that say so.
     */
    public function definition(): InstanceDefinition
    {
        return $this->definition ??= $this->loader->load();
    }

    /**
     * The row of an element of the configuration, which the data points
     * to: the role of an access, the workflow of a tracker, the type of a
     * link, the place of an issue. What the element says is read from the
     * definition; the row is only what the database ties the data to, so
     * it is asked for an element the definition has.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function row(string $class, string $id): object
    {
        return $this->entityManager->getReference($class, $id) ?? throw new \LogicException(\sprintf('The configuration has no %s "%s".', $class, $id));
    }

    public function reset(): void
    {
        $this->definition = null;
    }
}

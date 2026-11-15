<?php

declare(strict_types=1);

namespace App\Automation\Definition;

/**
 * One line of a rule: a trigger, a condition or an action, with the
 * values its type asks for.
 *
 * @template T of TriggerType|ConditionType|ActionType
 */
final readonly class RuleStep
{
    /**
     * @param T                    $type
     * @param array<string, mixed> $params
     */
    public function __construct(
        public TriggerType|ConditionType|ActionType $type,
        public array $params = [],
    ) {
    }

    /** A value as text, '' when it is not there. */
    public function text(string $name): string
    {
        $value = $this->params[$name] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * The values of a parameter that holds several, as texts; a single
     * value, as a rule written before the parameter took several, is a
     * list of one.
     *
     * @return list<string>
     */
    public function texts(string $name): array
    {
        $values = $this->params[$name] ?? [];

        return array_values(array_map(strval(...), array_filter(\is_array($values) ? $values : [$values], static fn (mixed $value): bool => \is_scalar($value) && '' !== $value)));
    }

    public function flag(string $name): bool
    {
        return true === ($this->params[$name] ?? false);
    }

    /**
     * @return array{type: string, params: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'params' => $this->params];
    }
}

<?php

declare(strict_types=1);

namespace App\Automation\Definition;

use App\Automation\AutomationException;

/**
 * What a rule says: when one thing happens (the trigger), if every
 * condition holds, do the actions in their order. Kept on the rule as an
 * array (AutomationRule::$definition), in the shape `toArray()` gives,
 * which is also what a project template carries (TemplateRule). `fromArray()` only
 * reads the shape; whether the values mean something on a project is
 * RuleChecker's.
 */
final readonly class RuleDefinition
{
    /**
     * @param RuleStep<TriggerType>         $trigger
     * @param list<RuleStep<ConditionType>> $conditions
     * @param list<RuleStep<ActionType>>    $actions
     */
    public function __construct(
        public RuleStep $trigger,
        public array $conditions,
        public array $actions,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $trigger = self::step($data['trigger'] ?? null, TriggerType::class);
        $conditions = [];
        foreach (self::steps($data['conditions'] ?? []) as $condition) {
            $conditions[] = self::step($condition, ConditionType::class);
        }
        $actions = [];
        foreach (self::steps($data['actions'] ?? []) as $action) {
            $actions[] = self::step($action, ActionType::class);
        }

        return new self($trigger, $conditions, $actions);
    }

    /**
     * @return array{trigger: array{type: string, params: array<string, mixed>}, conditions: list<array{type: string, params: array<string, mixed>}>, actions: list<array{type: string, params: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->trigger->toArray(),
            'conditions' => array_map(static fn (RuleStep $step): array => $step->toArray(), $this->conditions),
            'actions' => array_map(static fn (RuleStep $step): array => $step->toArray(), $this->actions),
        ];
    }

    /**
     * The days without activity the rule waits for, null when it reacts
     * to an event instead.
     */
    public function inactivityDays(): ?int
    {
        $days = $this->trigger->params['days'] ?? null;

        return $this->trigger->type->isTimed() && \is_int($days) && $days >= 1 ? $days : null;
    }

    /**
     * Whether a step of the rule only means something within one tracker.
     */
    public function needsTracker(): bool
    {
        foreach ([$this->trigger, ...$this->conditions, ...$this->actions] as $step) {
            foreach ($step->type->parameters() as $parameter) {
                if ($parameter->kind->needsTracker()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private static function steps(mixed $steps): array
    {
        if (!\is_array($steps)) {
            throw AutomationException::malformed('a list of steps was expected');
        }

        return array_values($steps);
    }

    /**
     * @template T of TriggerType|ConditionType|ActionType
     *
     * @param class-string<T> $enum
     *
     * @return RuleStep<T>
     */
    private static function step(mixed $step, string $enum): RuleStep
    {
        if (!\is_array($step) || !\is_string($step['type'] ?? null)) {
            throw AutomationException::malformed('a step has no type');
        }
        $type = $enum::tryFrom($step['type']) ?? throw AutomationException::unknownType($step['type']);
        $params = $step['params'] ?? [];
        if (!\is_array($params)) {
            throw AutomationException::malformed('the values of a step are not a map');
        }

        $kept = [];
        foreach ($type->parameters() as $parameter) {
            // A value left empty is not kept: the checker then says whether the step may do without it.
            if (!\in_array($params[$parameter->name] ?? null, [null, '', []], true)) {
                $kept[$parameter->name] = $params[$parameter->name];
            }
        }

        return new RuleStep($type, $kept);
    }
}

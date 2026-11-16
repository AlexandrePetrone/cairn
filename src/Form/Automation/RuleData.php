<?php

declare(strict_types=1);

namespace App\Form\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\ParameterKind;
use App\Automation\Definition\RuleChecker;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\TriggerType;
use App\Entity\AutomationRule;
use App\Entity\TemplateRule;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form of a rule collects, in the shape a rule is kept in
 * (RuleDefinition::toArray()): a step (the trigger, a condition, an
 * action) is its `type` and the `params` that type asks for, which is
 * what RuleType draws and what the commands take.
 */
final class RuleData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $name = '';

    /** The key of the tracker the rule is limited to, null or '' for every tracker. */
    public ?string $tracker = '';

    /** @var array{type: string, params: array<string, mixed>} */
    public array $trigger = ['type' => TriggerType::IssueCreated->value, 'params' => []];

    /** @var array<int, array{type: string, params: array<string, mixed>}> */
    public array $conditions = [];

    /** @var array<int, array{type: string, params: array<string, mixed>}> */
    public array $actions = [['type' => ActionType::Assign->value, 'params' => []]];

    public static function of(AutomationRule|TemplateRule|null $rule, RuleContext $context): self
    {
        return self::fromValues(null === $rule ? [] : [
            'name' => $rule->getName(),
            'tracker' => (string) $rule->getTracker()?->getId(),
            ...$rule->getDefinition()->toArray(),
        ], $context);
    }

    /**
     * Reads what a page holds, as typed or as stored, into steps the form
     * can draw: a step keeps the values of its type only (those of the
     * type it had before go), each in the PHP type of its field, and a
     * choice that is not one here goes too, as RuleChecker says: a place
     * of another workflow once the rule is limited to a tracker, a member
     * who left the project.
     *
     * @param array<array-key, mixed> $values
     */
    public static function fromValues(array $values, RuleContext $context): self
    {
        $data = new self();
        $data->name = \is_string($values['name'] ?? null) ? $values['name'] : '';
        $tracker = \is_scalar($values['tracker'] ?? null) ? (string) $values['tracker'] : '';
        $data->tracker = isset($context->trackers[$tracker]) ? $tracker : '';

        $scope = '' === $data->tracker ? null : $data->tracker;
        $steps = static fn (mixed $steps): array => \is_array($steps) ? array_values(array_filter($steps, is_array(...))) : [];
        // Nothing held yet: the rule a new page starts with, one action to fill.
        $held = \array_key_exists('trigger', $values) ? $values : ['trigger' => $data->trigger, 'actions' => $data->actions];

        $data->trigger = self::step(\is_array($held['trigger']) ? $held['trigger'] : [], TriggerType::class, $scope, $context);
        $data->conditions = array_map(static fn (array $step): array => self::step($step, ConditionType::class, $scope, $context), $steps($held['conditions'] ?? []));
        $data->actions = array_map(static fn (array $step): array => self::step($step, ActionType::class, $scope, $context), $steps($held['actions'] ?? []));

        return $data;
    }

    public function trackerId(): ?int
    {
        return '' === (string) $this->tracker ? null : (int) $this->tracker;
    }

    /**
     * @return array{trigger: array{type: string, params: array<string, mixed>}, conditions: list<array{type: string, params: array<string, mixed>}>, actions: list<array{type: string, params: array<string, mixed>}>}
     */
    public function definition(): array
    {
        return ['trigger' => $this->trigger, 'conditions' => array_values($this->conditions), 'actions' => array_values($this->actions)];
    }

    /**
     * @template T of TriggerType|ConditionType|ActionType
     *
     * @param array<array-key, mixed> $step
     * @param class-string<T>         $enum
     *
     * @return array{type: string, params: array<string, mixed>}
     */
    private static function step(array $step, string $enum, ?string $tracker, RuleContext $context): array
    {
        $type = (\is_string($step['type'] ?? null) ? $enum::tryFrom($step['type']) : null) ?? $enum::cases()[0];
        $given = \is_array($step['params'] ?? null) ? $step['params'] : [];
        $params = [];
        foreach ($type->parameters() as $parameter) {
            $value = $given[$parameter->name] ?? null;
            $allowed = static fn (mixed $one): bool => \is_scalar($one) && (!$parameter->kind->isChosen() || RuleChecker::allowsOne($parameter, (string) $one, $type, $tracker, $context));
            $params[$parameter->name] = match (true) {
                $parameter->multiple => array_values(array_map(strval(...), array_filter(\is_array($value) ? $value : [$value], $allowed))),
                ParameterKind::Days === $parameter->kind => is_numeric($value) ? (int) $value : null,
                ParameterKind::Flag === $parameter->kind => \in_array($value, [true, '1', 1, 'on'], true),
                default => $allowed($value) ? (string) $value : '',
            };
        }

        return ['type' => $type->value, 'params' => $params];
    }
}

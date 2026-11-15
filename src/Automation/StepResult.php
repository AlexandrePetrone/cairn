<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\ActionType;

/**
 * What one action of a rule did to an issue: changed it, found nothing
 * to change, or could not be done, and why. Kept in the execution
 * (AutomationRun::$steps) as `toArray()` gives it.
 */
final readonly class StepResult
{
    public const string APPLIED = 'applied';
    public const string UNCHANGED = 'unchanged';
    public const string SKIPPED = 'skipped';

    private function __construct(
        public ActionType $action,
        public string $result,
        public string $value,
        public ?string $reason,
    ) {
    }

    /**
     * @param string $value what the issue holds now: a name, a place, a criticality; '' when it says nothing
     */
    public static function applied(ActionType $action, string $value = ''): self
    {
        return new self($action, self::APPLIED, $value, null);
    }

    public static function unchanged(ActionType $action, string $value = ''): self
    {
        return new self($action, self::UNCHANGED, $value, null);
    }

    /**
     * @param string $reason a key of `automation.run.reason`, where `value` is what it is about
     */
    public static function skipped(ActionType $action, string $reason, string $value = ''): self
    {
        return new self($action, self::SKIPPED, $value, $reason);
    }

    public function isApplied(): bool
    {
        return self::APPLIED === $this->result;
    }

    public function isSkipped(): bool
    {
        return self::SKIPPED === $this->result;
    }

    /**
     * @return array{action: string, result: string, value?: string, reason?: string}
     */
    public function toArray(): array
    {
        return ['action' => $this->action->value, 'result' => $this->result]
            + ('' === $this->value ? [] : ['value' => $this->value])
            + (null === $this->reason ? [] : ['reason' => $this->reason]);
    }
}

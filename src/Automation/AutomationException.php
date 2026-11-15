<?php

declare(strict_types=1);

namespace App\Automation;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A rule cannot be kept as written, or does not exist. The message is
 * for the logs; the pages show the translated reason.
 */
final class AutomationException extends \DomainException implements TranslatableInterface
{
    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what, int $id): self
    {
        return new self(\sprintf('%s %d not found.', $what, $id), 'automation.refused.not_found');
    }

    public static function malformed(string $why): self
    {
        return new self(\sprintf('The rule is not written as a rule: %s.', $why), 'automation.refused.malformed');
    }

    public static function unknownType(string $type): self
    {
        return new self(\sprintf('"%s" is not a known trigger, condition or action.', $type), 'automation.refused.unknown_type', ['%type%' => $type]);
    }

    public static function noAction(): self
    {
        return new self('A rule needs at least one action.', 'automation.refused.no_action');
    }

    public static function tooManySteps(int $max): self
    {
        return new self(\sprintf('A rule holds %d conditions and %d actions at most.', $max, $max), 'automation.refused.too_many_steps', ['%max%' => (string) $max]);
    }

    public static function needsTracker(): self
    {
        return new self('A step of the rule names a place or an action of a workflow: the rule must be limited to a tracker.', 'automation.refused.needs_tracker');
    }

    public static function unknownTracker(): self
    {
        return new self('The tracker the rule is limited to is not one of the project.', 'automation.refused.unknown_tracker');
    }

    public static function invalidValue(string $step, string $parameter): self
    {
        return new self(\sprintf('The value of "%s" in "%s" is not one the rule may take here.', $parameter, $step), 'automation.refused.invalid_value', ['%step%' => $step, '%parameter%' => $parameter]);
    }

    public static function nobodyActs(): self
    {
        return new self('A rule that waits for time to pass cannot ask who acted: nobody did.', 'automation.refused.nobody_acts');
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

<?php

declare(strict_types=1);

namespace App\Automation\Definition;

/**
 * One value a step of a rule asks for: its name in the stored definition,
 * its kind, for a choice what is offered, and whether several values may
 * be chosen at once (the step then holds on any of them).
 */
final readonly class Parameter
{
    /**
     * @param list<string> $options  the values of a Choice, labelled `automation.option.<value>`
     * @param bool         $required an optional parameter left empty means "any"
     * @param bool         $multiple a list of values of the kind, one at least, kept as a list
     */
    public function __construct(
        public string $name,
        public ParameterKind $kind,
        public array $options = [],
        public bool $required = true,
        public bool $multiple = false,
    ) {
    }
}

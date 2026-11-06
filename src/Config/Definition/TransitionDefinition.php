<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Config\TransitionStyle;

/**
 * A named action that moves an issue from one of the `from` places to the
 * `to` place. Shown as a button to the roles listed.
 */
final readonly class TransitionDefinition
{
    /** @var list<string> */
    public array $from;

    /** @var list<string> */
    public array $roles;

    /**
     * @param list<string> $from
     * @param list<string> $roles           roles allowed to trigger the transition
     * @param bool         $requiresComment external users must comment when triggering it; internal users never have to
     * @param string|null  $commentPrompt   placeholder of the comment box when a comment is required of them
     * @param string|null  $description     what the action does, shown under it on the issue page
     */
    public function __construct(
        public string $id,
        public string $label,
        array $from,
        public string $to,
        array $roles,
        public TransitionStyle $style = TransitionStyle::Default,
        public bool $requiresComment = false,
        public ?string $commentPrompt = null,
        public ?string $description = null,
    ) {
        Definitions::assertId($id, 'transitions');
        Definitions::assertLabel($label, "transitions.$id.label");
        $this->from = Definitions::assertIdList($from, "transitions.$id.from");
        $this->roles = Definitions::assertIdList($roles, "transitions.$id.roles");

        if ([] === $this->from) {
            throw new InvalidDefinitionException(\sprintf('transitions.%s: at least one source place is required.', $id));
        }
        if ([] === $this->roles) {
            throw new InvalidDefinitionException(\sprintf('transitions.%s: at least one role is required.', $id));
        }
        if (null !== $commentPrompt && !$requiresComment) {
            throw new InvalidDefinitionException(\sprintf('transitions.%s: a comment prompt only makes sense when a comment is required.', $id));
        }
        if (null !== $description && '' === trim($description)) {
            throw new InvalidDefinitionException(\sprintf('transitions.%s: the description cannot be blank, leave it null instead.', $id));
        }
    }
}

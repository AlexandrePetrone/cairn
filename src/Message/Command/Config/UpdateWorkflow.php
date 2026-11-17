<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a workflow, PATCH style. `initial` is one of its places;
 * `places` gives the same places in another order, the columns of the
 * kanban; `fields` replaces the fields it asks for, in the order of the
 * form. Handled by UpdateWorkflowHandler.
 */
final readonly class UpdateWorkflow implements ConfigurationCommand
{
    public function __construct(
        public string $id,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        public ?string $initial = null,
        /** @var list<string>|null every place of the workflow, in the new order */
        public ?array $places = null,
        /** @var array<string, bool>|null field id => required, in the order of the form */
        public ?array $fields = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

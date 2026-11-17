<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds a role to the instance, after the others: the narrowest until it is
 * moved. Its key is the one given or one made from the label, for good.
 * Handled by CreateRoleHandler, which returns the Role.
 */
final readonly class CreateRole implements ConfigurationCommand
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        public bool $internalOnly = false,
        public bool $followsNewIssues = false,
        public ?string $id = null,
    ) {
    }
}

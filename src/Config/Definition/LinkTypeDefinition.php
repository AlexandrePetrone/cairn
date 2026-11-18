<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * A type of link between two issues. Without an inverse label the link reads
 * the same from both sides (symmetric).
 */
final readonly class LinkTypeDefinition
{
    /**
     * @param string|null $inverseLabel label seen from the target issue
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $inverseLabel = null,
    ) {
    }

    public function isSymmetric(): bool
    {
        return null === $this->inverseLabel;
    }

    /**
     * Label seen from the target issue: the inverse label, or the label
     * itself for a symmetric link.
     */
    public function labelFromTarget(): string
    {
        return $this->inverseLabel ?? $this->label;
    }
}

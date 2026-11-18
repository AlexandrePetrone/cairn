<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the dialog of a type of link edits. Whether the link reads the same
 * from both issues or has a direction is chosen when the type is created
 * and kept afterwards, as a field keeps its type: links of it exist.
 */
final class LinkTypeData
{
    public const string SAME = 'same';
    public const string DIRECTED = 'directed';

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\Choice(choices: [self::SAME, self::DIRECTED])]
    public string $nature = self::SAME;

    #[Assert\Length(max: 120)]
    public ?string $inverseLabel = null;

    #[Assert\Callback]
    public function validateInverse(ExecutionContextInterface $context): void
    {
        if (self::DIRECTED === $this->nature && '' === trim((string) $this->inverseLabel)) {
            $context->buildViolation('admin.configuration.links.inverse_required')->atPath('inverseLabel')->addViolation();
        }
    }

    /**
     * How the link reads from the other issue: null for one that reads the same from both.
     */
    public function inverse(): ?string
    {
        return self::DIRECTED === $this->nature ? $this->inverseLabel : null;
    }
}

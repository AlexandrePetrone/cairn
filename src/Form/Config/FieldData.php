<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Config\FieldType;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of a field edits. The choices are typed one per line,
 * `value = Label`, or the label alone for a new one, whose value is then
 * made from it: options() reads them, write() shows them.
 */
final class FieldData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\NotNull]
    public ?FieldType $type = FieldType::Text;

    public ?string $choices = null;

    #[Assert\Length(max: 255)]
    public ?string $placeholder = null;

    public ?string $help = null;

    public bool $internalOnly = false;

    /**
     * @return array<string, string> value => label, in the order typed
     */
    public function options(): array
    {
        $options = [];
        foreach (preg_split('/\R/', (string) $this->choices) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $value = '';
            $label = trim($line);
            if (str_contains($line, '=')) {
                [$value, $label] = array_map(trim(...), explode('=', $line, 2));
            }
            if ('' === $value) {
                $value = (new AsciiSlugger())->slug($label, '_')->lower()->toString();
            }
            $options[$value] = '' === $label ? $value : $label;
        }

        return $options;
    }

    /**
     * @param array<string, string> $options
     */
    public function write(array $options): void
    {
        $this->choices = implode("\n", array_map(static fn (string $value, string $label): string => $value.' = '.$label, array_keys($options), $options));
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Config\FieldType;
use App\Repository\FieldRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A field of the instance. Workflows pick which fields they ask for and
 * whether they are required; the values of a field no longer used are kept
 * but hidden. Its id is the key issues store their value under, and its
 * type never changes once created.
 */
#[ORM\Entity(repositoryClass: FieldRepository::class)]
#[ORM\Table(name: 'config_field')]
class Field implements Positioned
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 20, enumType: FieldType::class)]
    private FieldType $type;

    /**
     * A list rather than a map, so that the order holds whatever the database does with a JSON object.
     *
     * @var list<array{value: string, label: string}> the choices of a Select or a Choice, in their order
     */
    #[ORM\Column(type: Types::JSON)]
    private array $options;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $placeholder;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $help;

    /** Never shown to external users. */
    #[ORM\Column]
    private bool $internalOnly;

    #[ORM\Column]
    private int $position;

    /**
     * @param array<array-key, string> $options value => label
     */
    public function __construct(string $id, string $label, FieldType $type, array $options = [], ?string $placeholder = null, ?string $help = null, bool $internalOnly = false, int $position = 0)
    {
        $this->id = $id;
        $this->label = trim($label);
        $this->type = $type;
        $this->options = self::pairs($options);
        $this->setPlaceholder($placeholder);
        $this->setHelp($help);
        $this->internalOnly = $internalOnly;
        $this->position = $position;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): FieldType
    {
        return $this->type;
    }

    /**
     * @return array<string, string> value => label, in their order
     */
    public function getOptions(): array
    {
        return array_column($this->options, 'label', 'value');
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function isInternalOnly(): bool
    {
        return $this->internalOnly;
    }

    public function rename(string $label): void
    {
        $this->label = trim($label);
    }

    /**
     * @param array<array-key, string> $options value => label, in their order
     */
    public function offer(array $options): void
    {
        $this->options = self::pairs($options);
    }

    public function setPlaceholder(?string $placeholder): void
    {
        $this->placeholder = '' === trim((string) $placeholder) ? null : $placeholder;
    }

    public function setHelp(?string $help): void
    {
        $this->help = '' === trim((string) $help) ? null : $help;
    }

    public function makeInternalOnly(): void
    {
        $this->internalOnly = true;
    }

    public function showToExternalUsers(): void
    {
        $this->internalOnly = false;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    /**
     * @param array<array-key, string> $options
     *
     * @return list<array{value: string, label: string}>
     */
    private static function pairs(array $options): array
    {
        $pairs = [];
        foreach ($options as $value => $label) {
            $pairs[] = ['value' => (string) $value, 'label' => $label];
        }

        return $pairs;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueFieldValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The value an issue holds of a field: a row for each field filled in, none
 * for one left empty. A value points to its field as the rest of the data
 * points to the configuration, so it goes with the field when the field is
 * deleted, and no field made later can take it for its own. The values of
 * fields the workflow of the issue no longer asks for are kept.
 */
#[ORM\Entity(repositoryClass: IssueFieldValueRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_field_value', columns: ['issue_id', 'field_id'])]
class IssueFieldValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    /** The field, an element of the configuration: only its key is read from here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'field_id', nullable: false)]
    private Field $field;

    /** A text, a number, a date as text, a boolean or the value of a choice, as the type of the field gives it. */
    #[ORM\Column(type: Types::JSON)]
    private mixed $value;

    public function __construct(Issue $issue, Field $field, mixed $value)
    {
        $this->issue = $issue;
        $this->field = $field;
        $this->value = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    /**
     * The key of the field.
     */
    public function getFieldId(): string
    {
        return $this->field->getId();
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function change(mixed $value): void
    {
        $this->value = $value;
    }
}

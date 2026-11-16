<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Project;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a budget or on one of its lines cannot be carried out as
 * asked. The message is for the logs; the budgets pages show the
 * translated reason.
 */
final class BudgetException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('%s %d not found.', $what, $id), 'budget.refused.not_found');
    }

    public static function nameTaken(string $name): self
    {
        return new self(\sprintf('The project already has a budget named "%s".', $name), 'budget.refused.name_taken', ['%name%' => $name]);
    }

    /**
     * A project without a unit takes no budget activity, whatever writes
     * it: the budgets it had are kept as they are.
     *
     * @throws self
     */
    public static function unlessBudgeted(Project $project): void
    {
        if (!$project->hasBudgets()) {
            throw new self(\sprintf('Project "%s" has no budget: it has no unit.', $project->getName()), 'budget.refused.no_unit');
        }
    }

    public static function closed(string $name): self
    {
        return new self(\sprintf('Budget "%s" is closed: it takes no new line.', $name), 'budget.refused.closed', ['%name%' => $name]);
    }

    public static function stillOpen(string $name): self
    {
        return new self(\sprintf('Budget "%s" is open: it is closed before it is deleted.', $name), 'budget.refused.still_open', ['%name%' => $name]);
    }

    public static function issueOutsideProject(string $issueKey): self
    {
        return new self(\sprintf('Issue %s is not an issue of the project of the budget.', $issueKey), 'budget.refused.issue_outside_project', ['%key%' => $issueKey]);
    }

    /**
     * What a line must hold, whatever writes it: something it is for, and
     * a quantity once it is to approve or approved (zero is one).
     *
     * @throws self
     */
    public static function unlessComplete(bool $namesIssue, ?string $label, BudgetLineStatus $status, ?int $quantity): void
    {
        if (!$namesIssue && '' === trim((string) $label)) {
            throw new self('A line of a budget names an issue or carries a label.', 'budget.refused.subject_missing');
        }
        if ($status->needsQuantity() && null === $quantity) {
            throw new self(\sprintf('A line that is %s has a quantity.', $status->value), 'budget.refused.quantity_missing');
        }
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}

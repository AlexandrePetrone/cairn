<?php

declare(strict_types=1);

namespace App\Twig\Components\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\RuleDefinition;
use App\Automation\Definition\TriggerType;
use App\Automation\RuleSentence;
use App\Entity\AutomationRule;
use App\Entity\TemplateRule;
use App\Form\Automation\RuleData;
use App\Form\Automation\RuleType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;

/**
 * What the editors of a rule share, wherever the rule is written (a
 * project, a project template): the form rebuilt at each change from what
 * the page holds (RuleData::fromValues()), the rows added and removed,
 * the rule in one sentence, the deletion that waits for its confirmation.
 * The component says where it is (`context()`), which rule it edits
 * (`storedRule()`), and does the writing; both draw
 * `components/Automation/RuleForm.html.twig`, which also asks them
 * `getCancelUrl()` and `referentToday()`.
 */
trait EditsRule
{
    use ComponentWithFormTrait;

    /** Whether the deletion waits for its confirmation. */
    #[LiveProp]
    public bool $deleting = false;

    private ?RuleContext $loadedContext = null;

    abstract protected function ruleContext(): RuleContext;

    abstract protected function storedRule(): AutomationRule|TemplateRule|null;

    abstract protected function ruleSentence(): RuleSentence;

    abstract protected function formFactory(): FormFactoryInterface;

    /**
     * The rule as the page holds it, in one sentence.
     */
    public function getSentence(): string
    {
        $draft = $this->draft();

        return $this->ruleSentence()->of(RuleDefinition::fromArray($draft->definition()), '' === (string) $draft->tracker ? null : (string) $draft->tracker, $this->context());
    }

    /**
     * Whether the trigger chosen waits for time instead of an event.
     */
    public function isTimed(): bool
    {
        return true === TriggerType::tryFrom((string) ($this->draft()->trigger['type'] ?? ''))?->isTimed();
    }

    #[LiveAction]
    public function addCondition(): void
    {
        $this->addRow('conditions', ['type' => ConditionType::Assignee->value, 'params' => []]);
    }

    #[LiveAction]
    public function removeCondition(#[LiveArg] int $index): void
    {
        $this->removeRow('conditions', $index);
    }

    #[LiveAction]
    public function addAction(): void
    {
        $this->addRow('actions', ['type' => ActionType::Assign->value, 'params' => []]);
    }

    #[LiveAction]
    public function removeAction(#[LiveArg] int $index): void
    {
        $this->removeRow('actions', $index);
    }

    #[LiveAction]
    public function confirmDelete(): void
    {
        $this->deleting = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->deleting = false;
    }

    /**
     * The form of the rule as the page stands. The live endpoint requires
     * a custom header that keeps cross-origin requests out, so the form
     * carries no CSRF token of its own.
     *
     * @return FormInterface<RuleData>
     */
    protected function instantiateForm(): FormInterface
    {
        $draft = $this->draft();
        if ([] !== $this->formValues) {
            // The values follow the rows: those of a type a row no longer has leave, as does a place of another tracker.
            $this->formValues = $this->values($draft);
        }

        return $this->formFactory()->createNamed('automation_rule', RuleType::class, $draft, [
            'context' => $this->context(),
            'rule' => $draft,
            'csrf_protection' => false,
        ]);
    }

    private function context(): RuleContext
    {
        return $this->loadedContext ??= $this->ruleContext();
    }

    private function draft(): RuleData
    {
        return [] === $this->formValues ? RuleData::of($this->storedRule(), $this->context()) : RuleData::fromValues($this->formValues, $this->context());
    }

    /**
     * @return array<string, mixed>
     */
    private function values(RuleData $draft): array
    {
        return ['name' => $draft->name, 'tracker' => $draft->tracker, 'trigger' => $draft->trigger, 'conditions' => $draft->conditions, 'actions' => $draft->actions];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addRow(string $list, array $row): void
    {
        $values = $this->values($this->draft());
        $values[$list][] = $row;
        $this->formValues = $values;
    }

    private function removeRow(string $list, int $index): void
    {
        $values = $this->values($this->draft());
        unset($values[$list][$index]);
        $values[$list] = array_values($values[$list]);
        $this->formValues = $values;
    }
}

<?php

declare(strict_types=1);

namespace App\Twig\Components\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\RuleDefinition;
use App\Automation\Definition\Target;
use App\Automation\Definition\TriggerType;
use App\Automation\RuleContexts;
use App\Automation\RuleSentence;
use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Form\Automation\RuleData;
use App\Form\Automation\RuleType;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Automation\DeleteRule;
use App\Message\Command\Automation\UpdateRule;
use App\Message\CommandBus;
use App\Security\Permission;
use App\Security\Referents;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The editor of an automation rule of a project, a new one when `rule`
 * is null: its name, the tracker it is limited to, then the trigger, the
 * conditions and the actions. What a row asks for depends on its type,
 * and what a place or an action of a workflow offers on the tracker, so
 * the form is redrawn at each change and rebuilt from what the page
 * holds (RuleData::fromValues()); rows are added and removed here.
 * Beside it, the rule in one sentence. Nothing is kept until `save`,
 * which dispatches CreateRule or UpdateRule and leads back to the list;
 * deleting confirms in place.
 */
#[AsLiveComponent('Automation:RuleForm')]
final class RuleForm extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Project $project;

    #[LiveProp]
    public ?AutomationRule $rule = null;

    /** Whether the deletion waits for its confirmation. */
    #[LiveProp]
    public bool $deleting = false;

    private ?RuleContext $loadedContext = null;

    public function __construct(
        private readonly RuleContexts $contexts,
        private readonly RuleSentence $sentence,
        private readonly Referents $referents,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * The editor shows the private trackers and the members of the
     * project: it is drawn, and redrawn, only for who may configure it,
     * whatever page or request holds it. The commands guard the writing.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted(Permission::ProjectConfigure->value, $this->project);
        if (null !== $this->rule && $this->rule->getProject()->getId() !== $this->project->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * The rule as the page holds it, in one sentence.
     */
    public function getSentence(): string
    {
        $draft = $this->draft();

        return $this->sentence->of(RuleDefinition::fromArray($draft->definition()), '' === (string) $draft->tracker ? null : (string) $draft->tracker, $this->context());
    }

    /**
     * Whether the trigger chosen waits for time instead of an event.
     */
    public function isTimed(): bool
    {
        return true === TriggerType::tryFrom((string) ($this->draft()->trigger['type'] ?? ''))?->isTimed();
    }

    /**
     * Who a target that names a referent leads to today, null for any
     * other target: their `name`, and whether they `answer` (someone may
     * be designated whose account is deactivated, or who holds another
     * role since: the action is then skipped).
     *
     * @return array{name: string, answers: bool}|null
     */
    public function referentToday(string $target): ?array
    {
        $parsed = Target::parse($target);
        if (Target::REFERENT !== $parsed?->kind) {
            return null;
        }
        $referent = $this->referents->of($this->project, $parsed->argument);

        return ['name' => (string) ($referent ?? $this->referents->marked($this->project)[$parsed->argument] ?? null)?->getFullName(), 'answers' => null !== $referent];
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
    public function save(CommandBus $commands): ?Response
    {
        $this->submitForm();
        $data = $this->getForm()->getData();
        \assert($data instanceof RuleData);
        $actorId = $this->viewer->get()->getId();

        $command = null === $this->rule
            ? new CreateRule((int) $this->project->getId(), (string) $data->name, $data->trackerId(), $data->definition(), actorId: $actorId)
            : new UpdateRule((int) $this->rule->getId(), name: (string) $data->name, trackerId: $data->trackerId() ?? UpdateRule::NO_TRACKER, definition: $data->definition(), actorId: $actorId);
        if (!$this->tryCommand($commands, $command)) {
            return null;
        }
        $this->addFlash('success', $this->translator->trans(null === $this->rule ? 'automation.created' : 'automation.saved'));

        return $this->backToTheList();
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

    #[LiveAction]
    public function delete(CommandBus $commands): ?Response
    {
        $this->deleting = false;
        if (null === $this->rule || !$this->tryCommand($commands, new DeleteRule((int) $this->rule->getId(), $this->viewer->get()->getId()))) {
            return null;
        }
        $this->addFlash('success', $this->translator->trans('automation.deleted'));

        return $this->backToTheList();
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

        return $this->forms->createNamed('automation_rule', RuleType::class, $draft, [
            'context' => $this->context(),
            'rule' => $draft,
            'csrf_protection' => false,
        ]);
    }

    private function draft(): RuleData
    {
        return [] === $this->formValues ? RuleData::of($this->rule, $this->context()) : RuleData::fromValues($this->formValues, $this->context());
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

    private function context(): RuleContext
    {
        return $this->loadedContext ??= $this->contexts->ofProject($this->project);
    }

    private function backToTheList(): Response
    {
        return $this->redirectToRoute('app_project_settings_automations', ['workspaceKey' => $this->project->getWorkspace()->getKey(), 'projectKey' => $this->project->getKey()]);
    }
}

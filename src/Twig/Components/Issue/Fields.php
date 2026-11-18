<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\ConfigRegistry;
use App\Config\FieldValue;
use App\Entity\Issue;
use App\Form\Issue\IssueFieldsType;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Repository\IssueFieldValueRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The fields of the issue's workflow, a card of the side panel: read, or
 * edited as one form (the one of the creation) by whoever may edit the
 * issue.
 */
#[AsLiveComponent('Issue:Fields')]
final class Fields
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public bool $editing = false;

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly ConfigRegistry $config,
        private readonly IssueFieldValueRepository $held,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The filled-in fields, as the viewer may see them.
     *
     * @return list<FieldValue>
     */
    public function getValues(): array
    {
        $definition = $this->config->definition();

        return $definition->fieldValues($definition->workflow($this->issue->getTracker()->getWorkflowId()), $this->held->valuesOf($this->issue), $this->viewer->get()->isInternal());
    }

    /**
     * Whether the viewer may fill in a field: they may edit the issue and
     * its workflow has a field for them. A workflow without any, or with
     * internal-only ones for an external viewer, offers nothing to edit.
     */
    public function canEdit(): bool
    {
        $viewer = $this->viewer->get();
        if (!$this->access->canEditIssue($viewer, $this->issue)) {
            return false;
        }

        $definition = $this->config->definition();
        foreach ($definition->workflow($this->issue->getTracker()->getWorkflowId())->fields as $usage) {
            if ($viewer->isInternal() || !$definition->field($usage->field)->internalOnly) {
                return true;
            }
        }

        return false;
    }

    #[LiveAction]
    public function edit(): void
    {
        $this->editing = $this->canEdit();
        $this->resetForm();
    }

    #[LiveAction]
    public function cancel(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    #[LiveAction]
    public function save(CommandBus $commands): void
    {
        $this->submitForm();
        $values = $this->getForm()->getData();
        \assert(\is_array($values));

        if (!$this->tryCommand($commands, new UpdateIssue((int) $this->viewer->get()->getId(), $this->issue->getKey(), fields: $values))) {
            return;
        }

        $this->editing = false;
        $this->resetForm();
        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $this->issue->getKey()]));
    }

    /**
     * Redraws with the issue as another component left it.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $definition = $this->config->definition();
        $workflow = $definition->workflow($this->issue->getTracker()->getWorkflowId());

        return $this->forms->createNamed('fields', IssueFieldsType::class, array_intersect_key($this->held->valuesOf($this->issue), $workflow->fields), [
            'workflow' => $workflow,
            'definition' => $definition,
            'internal' => $this->viewer->get()->isInternal(),
            'csrf_protection' => false,
        ]);
    }

    /**
     * Field values are kept in sync without re-rendering: nothing depends
     * on them before saving.
     */
    private function getDataModelValue(): string
    {
        return 'norender|*';
    }
}

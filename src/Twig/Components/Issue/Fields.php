<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\ConfigRegistry;
use App\Config\FieldValue;
use App\Entity\Issue;
use App\Entity\User;
use App\Form\IssueFieldsType;
use App\Message\Command\Issue\ChangeFields;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Security\AccessControl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The fields of the issue's workflow in the side panel: read, or edited as
 * one form (the one of the creation) by whoever may edit the issue.
 */
#[AsLiveComponent('Issue:Fields')]
final class Fields extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public bool $editing = false;

    /** Why the last save did not go through. */
    public ?string $error = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
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

        return $definition->fieldValues($definition->workflow($this->issue->getTracker()->getWorkflowId()), $this->issue->getFieldValues(), $this->viewer()->isInternal());
    }

    public function canEdit(): bool
    {
        return $this->access->canEditIssue($this->viewer(), $this->issue);
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

        try {
            $commands->dispatch(new ChangeFields((int) $this->viewer()->getId(), $this->issue->getKey(), $values));
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.edit.refused');

            return;
        }

        $this->editing = false;
        $this->resetForm();
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.edit.done', ['%key%' => $this->issue->getKey()])]);
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

        return $this->forms->createNamed('fields', IssueFieldsType::class, array_intersect_key($this->issue->getFieldValues(), $workflow->fields), [
            'workflow' => $workflow,
            'definition' => $definition,
            'internal' => $this->viewer()->isInternal(),
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

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('Issue fields are only rendered for signed-in users.');
    }
}

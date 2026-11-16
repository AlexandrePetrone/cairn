<?php

declare(strict_types=1);

namespace App\Twig\Components\Template;

use App\Automation\Definition\RuleContext;
use App\Automation\RuleContexts;
use App\Automation\RuleSentence;
use App\Entity\ProjectTemplate;
use App\Entity\TemplateRule;
use App\Form\Automation\RuleData;
use App\Message\Command\Template\CreateTemplateRule;
use App\Message\Command\Template\DeleteTemplateRule;
use App\Message\Command\Template\UpdateTemplateRule;
use App\Message\CommandBus;
use App\Twig\Components\Automation\EditsRule;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The editor of an automation rule of a project template, a new one when
 * `rule` is null: the editor of a rule of a project (EditsRule, the same
 * form and the same template), in the context of the template: its own
 * trackers, the roles, nobody to name. `save` dispatches
 * CreateTemplateRule or UpdateTemplateRule and leads back to the rules of
 * the template.
 */
#[AsLiveComponent('Template:RuleForm', template: 'components/Automation/RuleForm.html.twig')]
final class RuleForm extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use EditsRule;

    #[LiveProp]
    public ProjectTemplate $projectTemplate;

    #[LiveProp]
    public ?TemplateRule $rule = null;

    public function __construct(
        private readonly RuleContexts $contexts,
        private readonly RuleSentence $sentence,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The live endpoint is not under `/admin`: the editor is drawn, and
     * redrawn, for administrators only, and its commands carry no actor.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (null !== $this->rule && $this->rule->getTemplate()->getId() !== $this->projectTemplate->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * A template has no member: nobody answers for a role there.
     */
    public function referentToday(string $target): null
    {
        return null;
    }

    public function getCancelUrl(): string
    {
        return $this->generateUrl('app_admin_template_automations', ['template' => $this->projectTemplate->getId()]);
    }

    #[LiveAction]
    public function save(CommandBus $commands): ?Response
    {
        $this->submitForm();
        $data = $this->getForm()->getData();
        \assert($data instanceof RuleData);

        $command = null === $this->rule
            ? new CreateTemplateRule((int) $this->projectTemplate->getId(), (string) $data->name, $data->trackerId(), $data->definition())
            : new UpdateTemplateRule((int) $this->rule->getId(), name: (string) $data->name, trackerId: $data->trackerId() ?? UpdateTemplateRule::NO_TRACKER, definition: $data->definition());
        if (!$this->tryCommand($commands, $command)) {
            return null;
        }
        $this->addFlash('success', $this->translator->trans(null === $this->rule ? 'automation.created' : 'automation.saved'));

        return $this->redirect($this->getCancelUrl());
    }

    #[LiveAction]
    public function delete(CommandBus $commands): ?Response
    {
        $this->deleting = false;
        if (null === $this->rule || !$this->tryCommand($commands, new DeleteTemplateRule((int) $this->rule->getId()))) {
            return null;
        }
        $this->addFlash('success', $this->translator->trans('automation.deleted'));

        return $this->redirect($this->getCancelUrl());
    }

    protected function ruleContext(): RuleContext
    {
        return $this->contexts->ofTemplate($this->projectTemplate);
    }

    protected function storedRule(): ?TemplateRule
    {
        return $this->rule;
    }

    protected function ruleSentence(): RuleSentence
    {
        return $this->sentence;
    }

    protected function formFactory(): FormFactoryInterface
    {
        return $this->forms;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Automation\AutomationException;
use App\Automation\RuleContexts;
use App\Entity\TemplateRule;
use App\Message\Command\Template\CreateTemplateRule;
use App\MessageHandler\Command\Automation\RuleWriting;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TemplateTrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTemplateRuleHandler
{
    public function __construct(
        private readonly ProjectTemplateRepository $templates,
        private readonly TemplateTrackerRepository $trackers,
        private readonly RuleContexts $contexts,
        private readonly RuleWriting $writing,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTemplateRule $command): TemplateRule
    {
        $template = $this->templates->find($command->templateId) ?? throw TemplateException::notFound('Template', $command->templateId);

        $tracker = null === $command->trackerId ? null : $this->trackers->find($command->trackerId);
        if (null !== $command->trackerId && $tracker?->getTemplate() !== $template) {
            throw AutomationException::unknownTracker();
        }
        $definition = $this->writing->written($command->definition, $tracker, $this->contexts->ofTemplate($template));

        $rule = new TemplateRule($template, $command->name, $tracker, $definition);
        if (!$command->active) {
            $rule->deactivate();
        }
        $this->entityManager->persist($rule);

        return $rule;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Config\ConfigRegistry;
use App\Entity\TemplateTracker;
use App\Entity\Workflow;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TemplateTrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTemplateTrackerHandler
{
    public function __construct(
        private readonly ProjectTemplateRepository $templates,
        private readonly TemplateTrackerRepository $trackers,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTemplateTracker $command): TemplateTracker
    {
        $template = $this->templates->find($command->templateId) ?? throw TemplateException::notFound('Template', $command->templateId);
        if (!$this->config->definition()->hasWorkflow($command->workflowId)) {
            throw TemplateException::unknownWorkflow($command->workflowId);
        }

        $tracker = new TemplateTracker($template, $command->name, $this->config->row(Workflow::class, $command->workflowId), \count($this->trackers->findByTemplate($template)));
        $tracker->setDescription($command->description);
        if ($command->private) {
            $tracker->makePrivate();
        }
        $this->entityManager->persist($tracker);

        return $tracker;
    }
}

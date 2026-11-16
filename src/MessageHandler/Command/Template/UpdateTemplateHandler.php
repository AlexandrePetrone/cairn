<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Message\Command\Template\UpdateTemplate;
use App\Repository\ProjectTemplateRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTemplateHandler
{
    public function __construct(
        private readonly ProjectTemplateRepository $templates,
    ) {
    }

    public function __invoke(UpdateTemplate $command): void
    {
        $template = $this->templates->find($command->templateId) ?? throw TemplateException::notFound('Template', $command->templateId);

        if (null !== $command->name) {
            $name = trim($command->name);
            $namesake = $this->templates->findOneBy(['name' => $name]);
            if (null !== $namesake && $namesake !== $template) {
                throw TemplateException::nameTaken($name);
            }
            $template->rename($name);
        }
        if (null !== $command->description) {
            $template->setDescription($command->description);
        }
    }
}

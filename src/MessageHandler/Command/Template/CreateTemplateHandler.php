<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Entity\ProjectTemplate;
use App\Message\Command\Template\CreateTemplate;
use App\Repository\ProjectTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTemplateHandler
{
    public function __construct(
        private readonly ProjectTemplateRepository $templates,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTemplate $command): ProjectTemplate
    {
        $name = trim($command->name);
        if (null !== $this->templates->findOneBy(['name' => $name])) {
            throw TemplateException::nameTaken($name);
        }

        $template = new ProjectTemplate($name);
        $template->setDescription($command->description);
        $this->entityManager->persist($template);

        return $template;
    }
}

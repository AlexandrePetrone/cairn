<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Message\Command\Template\DeleteTemplateRule;
use App\Repository\TemplateRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTemplateRuleHandler
{
    public function __construct(
        private readonly TemplateRuleRepository $rules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTemplateRule $command): void
    {
        $rule = $this->rules->find($command->ruleId) ?? throw TemplateException::notFound('Template rule', $command->ruleId);
        $this->entityManager->remove($rule);
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Field;
use App\Message\Command\Config\DeleteField;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\FieldRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteFieldHandler
{
    public function __construct(
        private readonly FieldRepository $fields,
        private readonly WorkflowRepository $workflows,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteField $command): void
    {
        $field = $this->writing->get(Field::class, $command->id);

        $workflows = $this->workflows->labelsUsingField($field);
        if ([] !== $workflows) {
            throw ConfigChangeException::fieldUsed($field->getLabel(), $workflows);
        }

        ListOrder::close($field, $this->fields->findOrdered());
        $this->entityManager->remove($field);
        $this->writing->settle('field.deleted', ['%name%' => $field->getLabel()]);
    }
}

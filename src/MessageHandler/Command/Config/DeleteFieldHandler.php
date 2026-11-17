<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Field;
use App\Message\Command\Config\DeleteField;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\FieldRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteFieldHandler
{
    public function __construct(
        private readonly FieldRepository $fields,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigDeletions $deletions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteField $command): void
    {
        $field = $this->writing->get(Field::class, $command->id);

        if (null !== $refusal = $this->deletions->field($field)) {
            throw ConfigChangeException::because($refusal);
        }

        ListOrder::close($field, $this->fields->findOrdered());
        $this->entityManager->remove($field);
        $this->writing->settle('field.deleted', ['%name%' => $field->getLabel()]);
    }
}

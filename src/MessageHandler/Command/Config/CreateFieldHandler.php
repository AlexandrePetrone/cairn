<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Field;
use App\Message\Command\Config\CreateField;
use App\Repository\FieldRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateFieldHandler
{
    public function __construct(
        private readonly FieldRepository $fields,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(CreateField $command): Field
    {
        $id = $this->writing->key($command->id, $command->label, fn (string $id): bool => null !== $this->fields->find($id));
        ConfigWriting::assertChoices($command->type, $command->options);

        $field = new Field($id, $command->label, $command->type, $command->options, $command->placeholder, $command->help, $command->internalOnly, \count($this->fields->findOrdered()));
        $this->entityManager->persist($field);
        $this->writing->settle('field.created', ['%name%' => $field->getLabel()]);

        return $field;
    }
}

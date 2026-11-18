<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\LinkType;
use App\Message\Command\Config\UpdateLinkType;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\LinkTypeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateLinkTypeHandler
{
    public function __construct(
        private readonly LinkTypeRepository $types,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdateLinkType $command): void
    {
        $type = $this->writing->get(LinkType::class, $command->id);

        // Links of the type exist either way: one that reads the same from both issues gains no direction, nor loses one.
        if (null !== $command->inverseLabel && (null === $type->getInverseLabel()) !== ('' === trim($command->inverseLabel))) {
            throw ConfigChangeException::linkNatureKept($type->getLabel());
        }

        if (null !== $command->label) {
            $type->rename($command->label);
        }
        if (null !== $command->inverseLabel && null !== $type->getInverseLabel()) {
            $type->setInverseLabel($command->inverseLabel);
        }
        if (null !== $command->position) {
            ListOrder::move($type, $this->types->findOrdered(), $command->position);
        }
        $this->writing->settle('link.updated', ['%name%' => $type->getLabel()]);
    }
}

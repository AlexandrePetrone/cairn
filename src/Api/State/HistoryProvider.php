<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\Refs;
use App\Api\Resource\HistoryEntryResource;
use App\Twig\JournalLabelExtension;

/**
 * The journal of an issue as the viewer may read it, oldest first, each
 * entry said as the issue page says it.
 *
 * @implements ProviderInterface<HistoryEntryResource>
 */
final class HistoryProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly Refs $refs,
        private readonly JournalLabelExtension $journal,
    ) {
    }

    /**
     * @return list<HistoryEntryResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $detail = $this->lookup->detail((string) $uriVariables['key']);
        $entries = [];
        foreach ($detail->timeline as $entry) {
            $event = $entry->event;
            if (null === $event) {
                continue;
            }
            $entries[] = new HistoryEntryResource(
                id: (int) $event->getId(),
                issue: $detail->issue->getKey(),
                type: $event->getType()->value,
                label: $this->journal->label($event),
                at: $event->getAt(),
                author: null === $event->getAuthor() ? null : $this->refs->user($event->getAuthor()),
                private: $event->isPrivate(),
                comment: $event->getComment()?->getId(),
                rule: $event->getRule(),
            );
        }

        return $entries;
    }
}

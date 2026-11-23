<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Tag;

use App\Entity\Tag;
use App\Message\Query\Tag\ListTags;
use App\Repository\TagRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListTagsHandler
{
    public function __construct(
        private readonly TagRepository $tags,
    ) {
    }

    /**
     * @return list<Tag>
     */
    public function __invoke(ListTags $query): array
    {
        return $this->tags->findByProject($query->project);
    }
}

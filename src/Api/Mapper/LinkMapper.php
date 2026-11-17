<?php

declare(strict_types=1);

namespace App\Api\Mapper;

use App\Api\Resource\LinkResource;
use App\Config\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\IssueLink;

/**
 * A link as one of its two ends reads it: its label from that side, the
 * issue at the other end.
 */
final class LinkMapper
{
    public function __construct(
        private readonly Refs $refs,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function map(IssueLink $link, Issue $issue): LinkResource
    {
        $type = $this->config->definition()->link($link->getTypeId());
        $inverse = $link->getTarget() === $issue && $link->getSource() !== $issue;

        return new LinkResource(
            id: (int) $link->getId(),
            issue: $issue->getKey(),
            type: $link->getTypeId(),
            label: $inverse ? $type->labelFromTarget() : $type->label,
            inverse: $inverse,
            other: $this->refs->issue($link->otherThan($issue)),
            createdAt: $link->getCreatedAt(),
        );
    }
}

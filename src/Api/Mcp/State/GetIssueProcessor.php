<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\CommentMapper;
use App\Api\Mapper\IssueMapper;
use App\Api\Mapper\LinkMapper;
use App\Api\Mcp\GetIssueTool;
use App\Api\Mcp\IssueView;
use App\Api\Resource\LinkResource;
use App\Api\State\Lookup;
use App\Entity\IssueLink;
use App\Message\Query\Issue\ListLinks;
use App\Message\QueryBus;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<GetIssueTool, IssueView>
 */
final class GetIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly IssueMapper $issues,
        private readonly CommentMapper $comments,
        private readonly LinkMapper $links,
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueView
    {
        \assert($data instanceof GetIssueTool);

        $detail = $this->lookup->detail($data->key);

        return new IssueView(
            $this->issues->detail($detail),
            $this->comments->all($detail),
            array_map(
                fn (IssueLink $link): LinkResource => $this->links->map($link, $detail->issue),
                $this->queries->ask(new ListLinks($this->viewer->get(), $detail->issue)),
            ),
        );
    }
}

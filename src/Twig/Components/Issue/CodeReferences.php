<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\CodeReference;
use App\Entity\Issue;
use App\Message\Query\Issue\ListCodeReferences;
use App\Message\QueryBus;
use App\Security\Viewer;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The code of the issue, a card of its side panel, for
 * internal users: the merge requests with their state, the commits by
 * branch. Hidden when there is nothing. Redrawn on `issue:changed`.
 */
#[AsLiveComponent('Issue:CodeReferences')]
final class CodeReferences
{
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    /** @var list<CodeReference>|null read once per render */
    private ?array $references = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * @return list<CodeReference>
     */
    public function getMergeRequests(): array
    {
        return array_values(array_filter($this->references(), static fn (CodeReference $reference): bool => $reference->isMergeRequest()));
    }

    /**
     * The commits, the latest first, under their branch; the branch of the
     * latest commit comes first.
     *
     * @return array<string, list<CodeReference>>
     */
    public function getCommitsByBranch(): array
    {
        $byBranch = [];
        foreach ($this->references() as $reference) {
            if (!$reference->isMergeRequest()) {
                $byBranch[$reference->getBranch() ?? ''][] = $reference;
            }
        }

        return $byBranch;
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
        $this->references = null;
    }

    /**
     * @return list<CodeReference>
     */
    private function references(): array
    {
        return $this->references ??= $this->queries->ask(new ListCodeReferences($this->viewer->get(), $this->issue));
    }
}

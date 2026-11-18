<?php

declare(strict_types=1);

namespace App\Api\Mapper;

use App\Api\Resource\IssueResource;
use App\Api\Resource\Value\IssueField;
use App\Api\Resource\Value\NamedRef;
use App\Api\Resource\Value\OfferedTransition;
use App\Api\Resource\Value\PlaceRef;
use App\Config\ConfigRegistry;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Message\Query\Issue\IssueDetail;
use App\Repository\IssueFieldValueRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Workflow\WorkflowProvider;

/**
 * What the API shows of an issue to the viewer of the request: what
 * depends on them (the internal fields, the actions offered and where
 * they lead) is decided with the rules of the pages, never assumed.
 */
final class IssueMapper
{
    public function __construct(
        private readonly Refs $refs,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly WorkflowProvider $workflows,
        private readonly IssueFieldValueRepository $held,
    ) {
    }

    /**
     * An issue as a list shows it: without its description.
     */
    public function summary(Issue $issue): IssueResource
    {
        return $this->map($issue);
    }

    /**
     * An issue as its page shows it: its description, its fields, its own files and the
     * actions the viewer may apply from its place.
     */
    public function detail(IssueDetail $detail): IssueResource
    {
        $viewer = $this->viewer->get();
        $issue = $detail->issue;
        $workflow = $this->workflows->definition($issue->getTracker()->getWorkflowId());
        $raw = $this->held->valuesOf($issue);

        $fields = [];
        foreach ($this->config->definition()->fieldValues($workflow, $raw, $viewer->isInternal()) as $fieldValue) {
            $fields[] = new IssueField($fieldValue->field->id, $fieldValue->field->label, $raw[$fieldValue->field->id], $fieldValue->value);
        }
        $transitions = [];
        foreach ($this->access->transitionsFor($viewer, $issue) as $transition) {
            $transitions[] = new OfferedTransition(
                $transition->id,
                $transition->label,
                $transition->description,
                $this->access->commentRequired($viewer, $transition),
                $viewer->isInternal() ? new PlaceRef($transition->to, $this->workflows->place($transition->to)->label) : null,
            );
        }

        return $this->map($issue, $issue->getDescription(), $fields, array_map($this->refs->file(...), $detail->issueAttachments()), $transitions);
    }

    /**
     * @param list<IssueField>|null                      $fields
     * @param list<\App\Api\Resource\Value\FileRef>|null $attachments
     * @param list<OfferedTransition>|null               $transitions
     */
    private function map(Issue $issue, ?string $description = null, ?array $fields = null, ?array $attachments = null, ?array $transitions = null): IssueResource
    {
        $release = $issue->getRelease();

        $closed = PlaceStatus::Closed === $this->workflows->placeOf($issue)->status;

        return new IssueResource(
            key: $issue->getKey(),
            title: $issue->getTitle(),
            description: $description,
            project: $issue->getProject()->getIssuePrefix(),
            tracker: new NamedRef((int) $issue->getTracker()->getId(), $issue->getTracker()->getName()),
            place: new PlaceRef($issue->getPlace(), $this->workflows->placeOf($issue)->label),
            closed: $closed,
            criticality: $issue->getCriticality()->value,
            private: $issue->isPrivate(),
            author: $this->refs->user($issue->getAuthor()),
            assignee: null === $issue->getAssignee() ? null : $this->refs->user($issue->getAssignee()),
            release: null === $release ? null : new NamedRef((int) $release->getId(), $release->getName()),
            createdAt: $issue->getCreatedAt(),
            updatedAt: $issue->getUpdatedAt(),
            // The date of the last move to a closed step: said only while the issue is closed.
            closedAt: $closed ? $issue->getClosedAt() : null,
            url: $this->refs->issueUrl($issue),
            importedRef: $issue->getImportedRef(),
            fields: $fields,
            attachments: $attachments,
            transitions: $transitions,
        );
    }
}

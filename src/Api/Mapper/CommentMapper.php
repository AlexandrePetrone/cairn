<?php

declare(strict_types=1);

namespace App\Api\Mapper;

use App\Api\Resource\CommentResource;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Message\Query\Issue\IssueDetail;

/**
 * The comments of an issue as the API shows them, each with its files.
 */
final class CommentMapper
{
    public function __construct(
        private readonly Refs $refs,
    ) {
    }

    /**
     * The comments the viewer may read, oldest first.
     *
     * @return list<CommentResource>
     */
    public function all(IssueDetail $detail): array
    {
        $comments = [];
        foreach ($detail->timeline as $entry) {
            $comment = $entry->comment ?? $entry->event?->getComment();
            if (null !== $comment) {
                // A comment edited since is also carried by the entries of its edits.
                $comments[(int) $comment->getId()] ??= $this->map($comment, $detail->commentAttachments($comment));
            }
        }
        usort($comments, static fn (CommentResource $a, CommentResource $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $comments;
    }

    /**
     * @param list<Attachment> $attachments the files of the comment
     */
    private function map(Comment $comment, array $attachments): CommentResource
    {
        return new CommentResource(
            id: (int) $comment->getId(),
            issue: $comment->getIssue()->getKey(),
            author: $this->refs->user($comment->getAuthor()),
            body: $comment->getBody(),
            private: $comment->isPrivate(),
            createdAt: $comment->getCreatedAt(),
            editedAt: $comment->getEditedAt(),
            attachments: array_map($this->refs->file(...), $attachments),
        );
    }
}

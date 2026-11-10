<?php

declare(strict_types=1);

namespace App\Api\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What POST /issues/{key}/transitions takes: ApplyTransition without its
 * actor nor its issue.
 */
final class TransitionInput
{
    /** Id of one of the `transitions` the issue offers. */
    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $transition = null;

    /** Markdown; some actions require it of external users. */
    public ?string $comment = null;

    public bool $commentPrivate = false;

    /**
     * Uuids of files uploaded beforehand, attached to the comment.
     *
     * @var list<string>
     */
    #[Assert\All([new Assert\Uuid()])]
    public array $attachments = [];
}

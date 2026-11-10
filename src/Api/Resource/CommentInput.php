<?php

declare(strict_types=1);

namespace App\Api\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What writing a comment takes: AddComment or EditComment without their
 * actor. `private` is read when the comment is created only.
 */
final class CommentInput
{
    /** Markdown. */
    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $body = null;

    public bool $private = false;

    /**
     * Uuids of files uploaded beforehand.
     *
     * @var list<string>
     */
    #[Assert\All([new Assert\Uuid()])]
    public array $attachments = [];
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Attachment;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `attachment_url(file)`: where an attachment is served.
 */
final class AttachmentExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[AsTwigFunction('attachment_url')]
    public function url(Attachment $attachment): string
    {
        return $this->urls->generate('app_attachment_show', ['uuid' => $attachment->getUuid()->toRfc4122(), 'filename' => $attachment->getFilename()]);
    }
}

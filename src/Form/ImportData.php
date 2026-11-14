<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the new import form collects: a zip sent with the form or the
 * name of one waiting on the server, and the project its issues go to.
 * The controller stores a sent zip, then dispatches CreateImport.
 */
final class ImportData
{
    #[Assert\File(mimeTypes: ['application/zip', 'application/x-zip-compressed'], mimeTypesMessage: 'admin.import.zip_expected')]
    public ?UploadedFile $upload = null;

    public ?string $archive = null;

    #[Assert\NotNull]
    public ?Project $project = null;

    #[Assert\Callback]
    public function validateArchive(ExecutionContextInterface $context): void
    {
        if (null === $this->upload && null === $this->archive) {
            $context->buildViolation('admin.import.archive_required')->atPath('upload')->addViolation();
        }
    }
}

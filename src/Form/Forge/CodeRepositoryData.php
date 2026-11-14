<?php

declare(strict_types=1);

namespace App\Form\Forge;

use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form linking a repository collects: the forge and the path of
 * the repository there. The controller turns it into LinkRepository.
 */
final class CodeRepositoryData
{
    public ?Forge $forge = Forge::GitLab;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(CodeRepository::PATH_PATTERN, message: 'code_repository.path_invalid')]
    public ?string $path = null;
}

<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Mercure topics of a project, and whether there is a hub at all:
 * real time is optional, and without MERCURE_URL nothing is published and
 * no page listens. A project has two topics because what it shows has two
 * audiences: everyone who sees the project hears of what external users
 * may see, internal users alone hear of the rest (private issues, private
 * trackers, private comments).
 */
final class ProjectTopics
{
    public function __construct(
        #[Autowire(env: 'default::MERCURE_URL')]
        private readonly ?string $hubUrl,
    ) {
    }

    public function enabled(): bool
    {
        return null !== $this->hubUrl && '' !== $this->hubUrl;
    }

    public function everyone(Project $project): string
    {
        return \sprintf('projects/%d', $project->getId());
    }

    public function internal(Project $project): string
    {
        return \sprintf('projects/%d/internal', $project->getId());
    }

    /**
     * What this person listens to on a page of the project: nothing
     * without a hub.
     *
     * @return list<string>
     */
    public function heardBy(User $user, Project $project): array
    {
        return match (true) {
            !$this->enabled() => [],
            $user->isInternal() => [$this->everyone($project), $this->internal($project)],
            default => [$this->everyone($project)],
        };
    }
}

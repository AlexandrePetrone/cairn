<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Project;
use App\Realtime\ProjectTopics;
use App\Security\Viewer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Twig\Attribute\AsTwigFunction;

/**
 * What a page of a project listens to, for its viewer: the Mercure topics
 * a template hands to `mercure()` for the address of the hub, and the
 * token that proves to the hub that this page may listen to them, and to
 * nothing else. The token is given to the page, which sends it itself: a
 * cookie would be shared by every tab, and a tab on another project would
 * take the right away from this one. Without a hub there is no topic, and
 * the page listens to nothing.
 */
final class RealtimeExtension
{
    /** How long a page left open goes on listening: a working day. */
    private const string LIFETIME = '+12 hours';

    public function __construct(
        private readonly ProjectTopics $topics,
        private readonly Viewer $viewer,
        private readonly HubInterface $hub,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string>
     */
    #[AsTwigFunction('realtime_topics')]
    public function topics(Project $project): array
    {
        return $this->topics->heardBy($this->viewer->get(), $project);
    }

    /**
     * A token, signed for the hub, that lets its holder subscribe to the
     * topics of `realtime_topics(project)`.
     */
    #[AsTwigFunction('realtime_token')]
    public function token(Project $project): string
    {
        $factory = $this->hub->getFactory() ?? throw new \LogicException('The Mercure hub has no secret to sign tokens with.');

        return $factory->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], $this->topics($project))],
            ['exp' => $this->clock->now()->modify(self::LIFETIME)],
        );
    }
}

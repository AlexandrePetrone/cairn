<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Keeps the tokens of a user to the pace of the `api` limiter (config/packages/
 * rate_limiter.yaml): beyond it the request is refused with 429 and the
 * time to wait. Runs right after the firewall, which named the token.
 */
final class ApiRateLimiter
{
    public function __construct(
        #[Target('api.limiter')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function limit(RequestEvent $event): void
    {
        $token = ApiTokenHandler::tokenOf($event->getRequest());
        if (!$event->isMainRequest() || null === $token) {
            return;
        }

        // By user, not by token: creating tokens does not buy more requests.
        $limit = $this->limiter->create((string) $token->getUser()->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The identity provider of the instance (OpenID Connect), optional: the one
 * place that says whether there is one. It is configured by the
 * environment (OIDC_PROVIDER_URI, OIDC_CLIENT_ID, OIDC_CLIENT_SECRET, read
 * by the `oidc_login` authenticator of the `main` firewall); without a
 * provider the sign-in page shows no button and no account can be told to
 * sign in through it, and the two addresses of the authenticator
 * (`start_path` and `check_path` in security.yaml) do not exist.
 */
final class SingleSignOn
{
    private const string PATH = '/login/sso';

    public function __construct(
        #[Autowire(env: 'OIDC_PROVIDER_URI')]
        private readonly string $providerUri,
        #[Autowire(env: 'OIDC_NAME')]
        private readonly string $name,
    ) {
    }

    public function enabled(): bool
    {
        return '' !== trim($this->providerUri);
    }

    /**
     * Before the firewall, which could not build an authenticator without
     * a provider to talk to.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
    public function closeWithoutProvider(RequestEvent $event): void
    {
        if ($this->enabled() || !$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (self::PATH === $path || str_starts_with($path, self::PATH.'/')) {
            throw new NotFoundHttpException('No identity provider is configured.');
        }
    }

    /**
     * What people call the provider ("Google", the name of the company
     * directory): content, shown as it is on the button and in the pages.
     */
    public function name(): string
    {
        return '' !== trim($this->name) ? trim($this->name) : 'SSO';
    }
}

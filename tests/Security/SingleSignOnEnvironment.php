<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\After;

/**
 * Gives the instance of a test an identity provider, which the test
 * environment has none of: the variables that configure one, read when the
 * kernel boots, so `configureSso()` comes first in `setUp()`. They are put
 * back as they were when the test ends.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait SingleSignOnEnvironment
{
    private const string SSO_ISSUER = 'https://sso.test';
    private const string SSO_CLIENT = 'cairn';

    /** @var array<string, string|false> */
    private array $ssoEnvironmentBefore = [];

    private function configureSso(): void
    {
        foreach (['OIDC_PROVIDER_URI' => self::SSO_ISSUER, 'OIDC_CLIENT_ID' => self::SSO_CLIENT, 'OIDC_CLIENT_SECRET' => 'secret', 'OIDC_NAME' => 'Acme ID'] as $name => $value) {
            $this->ssoEnvironmentBefore[$name] = $_ENV[$name] ?? false;
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    #[After]
    protected function restoreSsoEnvironment(): void
    {
        foreach ($this->ssoEnvironmentBefore as $name => $value) {
            if (false === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        $this->ssoEnvironmentBefore = [];
    }
}

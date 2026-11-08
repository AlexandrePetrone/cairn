<?php

declare(strict_types=1);

namespace App\Forge;

use App\Entity\Enum\Forge;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What Cairn asks a forge through its API, with the instance-level token
 * of the agency: installing and removing the webhook of a linked
 * repository. One implementation per forge, found by ForgeApiLocator.
 * Every call throws ForgeApiException when the forge refuses or is
 * unreachable.
 */
#[AutoconfigureTag('app.forge_api')]
interface ForgeApi
{
    public function forge(): Forge;

    /** Whether an instance token is set: without one, webhooks are installed by hand. */
    public function isConfigured(): bool;

    /** Where this forge delivers to, absolute, built from `DEFAULT_URI`: it goes to the forge, through the API or by hand. */
    public function deliveryUrl(): string;

    /**
     * Creates a webhook on the repository for pushes and merge requests,
     * sending the secret with each delivery. Returns the hook's id.
     */
    public function installHook(string $repositoryPath, #[\SensitiveParameter] string $secret): string;

    public function updateHookSecret(string $repositoryPath, string $hookId, #[\SensitiveParameter] string $secret): void;

    /** A hook already gone is not an error. */
    public function uninstallHook(string $repositoryPath, string $hookId): void;
}

<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Resource\MeResource;
use App\Security\ApiTokenHandler;
use App\Security\Viewer;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<MeResource>
 */
final class MeProvider implements ProviderInterface
{
    public function __construct(
        private readonly Viewer $viewer,
        private readonly RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MeResource
    {
        $token = ApiTokenHandler::tokenOf($this->requests->getMainRequest());
        \assert(null !== $token);

        return MeResource::of($this->viewer->get(), $token);
    }
}

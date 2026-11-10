<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Message\CommandBus;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dispatches the command of an API write and turns a refusal into the
 * answer a client reads, the pendant of Twig/Components/DispatchesCommands:
 * an invalid command is a 422 listing its violations, a refusal of the
 * domain a 422 giving the reason, in its own words when the exception can
 * be translated. A missing right stays the AccessDeniedException the
 * handler threw: 403.
 */
final class CommandDispatcher
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function dispatch(object $command): mixed
    {
        try {
            return $this->commands->dispatch($command);
        } catch (ValidationFailedException $e) {
            throw new ValidationException($e->getViolations(), previous: $e);
        } catch (\DomainException $e) {
            throw new UnprocessableEntityHttpException($e instanceof TranslatableInterface ? $e->trans($this->translator) : $e->getMessage(), $e);
        }
    }
}

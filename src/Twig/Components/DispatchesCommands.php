<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Message\CommandBus;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * For the live components whose actions are commands: a refusal becomes
 * `$error`, which the component shows, and on the issue page a success is
 * announced to the other components (`issue:changed`) with a toast. Needs
 * ComponentToolsTrait and a `translator`.
 */
trait DispatchesCommands
{
    /** Why the last action did not go through, shown by the component. */
    public ?string $error = null;

    /**
     * Dispatches the command. A refusal, whether a domain rule, a missing
     * right or an invalid command, is told to the user, never raised: in
     * its own words when the domain exception can be translated.
     *
     * @param string|null $invalidMessage what to say when the command is invalid, when the generic refusal would not help
     *
     * @return bool whether the command went through
     */
    private function tryCommand(CommandBus $commands, object $command, ?string $invalidMessage = null): bool
    {
        $this->error = $this->refusal($commands, $command, $invalidMessage);

        return null === $this->error;
    }

    /**
     * Dispatches the command and says why it was refused, or null when it
     * went through; for the actions that dispatch several commands and
     * report each refusal.
     */
    private function refusal(CommandBus $commands, object $command, ?string $invalidMessage = null): ?string
    {
        try {
            $commands->dispatch($command);
        } catch (ValidationFailedException) {
            return $invalidMessage ?? $this->translator->trans('issue.action.refused');
        } catch (\DomainException|AccessDeniedException $e) {
            return $e instanceof TranslatableInterface ? $e->trans($this->translator) : $this->translator->trans('issue.action.refused');
        }

        return null;
    }

    /**
     * The issue changed: the other components redraw, the user is told.
     */
    private function announce(string $message): void
    {
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $message]);
    }
}

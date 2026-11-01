<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * For the live components of the issue page, whose actions are commands:
 * a refusal becomes `$error`, which the component shows, and a success is
 * announced to the other components (`issue:changed`) with a toast. Needs
 * ComponentToolsTrait and a `translator`.
 */
trait DispatchesCommands
{
    /** Why the last action did not go through, shown by the component. */
    public ?string $error = null;

    /**
     * Dispatches the command. A refusal, whether a domain rule, a missing
     * right or an invalid command, is told to the user, never raised.
     *
     * @param string|null $invalidMessage what to say when the command is invalid, when the generic refusal would not help
     *
     * @return bool whether the command went through
     */
    private function tryCommand(CommandBus $commands, object $command, ?string $invalidMessage = null): bool
    {
        try {
            $commands->dispatch($command);
        } catch (ValidationFailedException) {
            $this->error = $invalidMessage ?? $this->translator->trans('issue.action.refused');

            return false;
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.action.refused');

            return false;
        }

        return true;
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

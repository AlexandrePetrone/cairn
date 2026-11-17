<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\OpensDialog;
use App\MessageHandler\Command\Config\ConfigChangeException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * For the controllers of the configuration pages, which all work alike: a
 * list, and dialogs with an address of their own that create, change and
 * delete what it holds. A refusal of a command is the error of the form
 * of the dialog, in its own words; the refusal of a deletion, which has no
 * form, is said when the page shows again.
 *
 * Their addresses name the list, and what creates in it, in the plural
 * (`/places`, `/places/new`) and one element in the singular, followed
 * by its key (`/place/to_test`): a key never stands where a word of the
 * address may, so any key is allowed, `new` included.
 *
 * The class that uses it has `$commands` (CommandBus) and `$translator`.
 */
trait EditsConfiguration
{
    use OpensDialog;

    /**
     * Dispatches the command, or the commands, a valid submission makes.
     *
     * @param FormInterface<mixed>              $form
     * @param \Closure(): (object|list<object>) $command
     * @param mixed                             $result  what the handler returned: the entity a creation made
     *
     * @return bool whether the change is made
     */
    private function applies(FormInterface $form, \Closure $command, mixed &$result = null): bool
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return false;
        }

        try {
            // Several commands are sent in their order, each in its own transaction: a refusal stops there.
            $commands = $command();
            foreach (\is_array($commands) ? $commands : [$commands] as $each) {
                $result = $this->commands->dispatch($each);
            }
        } catch (ConfigChangeException $e) {
            $form->addError(new FormError($e->trans($this->translator)));

            return false;
        } catch (ValidationFailedException $e) {
            foreach ($e->getViolations() as $violation) {
                $form->addError(new FormError((string) $violation->getMessage()));
            }

            return false;
        }

        return true;
    }

    /**
     * Dispatches a command that has no form, a deletion: done or refused,
     * the page that follows says so.
     */
    private function attempt(object $command, string $done): void
    {
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $done);
        } catch (ConfigChangeException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }
    }
}

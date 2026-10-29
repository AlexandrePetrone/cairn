<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\IssueData;
use App\Form\IssueType;
use App\Message\Command\Issue\CreateIssue;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The new issue screen in one page: the tracker first, then the form built
 * for its workflow and for what the viewer may qualify. A valid submission
 * is a CreateIssue command, then a redirect to the new issue.
 */
#[AsLiveComponent('Issue:CreateForm')]
final class CreateForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Project $project;

    #[LiveProp(writable: true, onUpdated: 'onTrackerChanged')]
    public ?int $trackerId = null;

    /** A refusal of the command, shown above the form. */
    public ?string $failure = null;

    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly ConfigRegistry $config,
        private readonly QueryBus $queries,
        private readonly FormFactoryInterface $forms,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * The trackers the viewer may file into: private ones only when internal.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        return $this->trackers->findByProject($this->project, includePrivate: $this->viewer()->isInternal());
    }

    /**
     * The chosen tracker, if it is one of those offered; a forged id is ignored.
     */
    public function getTracker(): ?Tracker
    {
        foreach ($this->getTrackers() as $tracker) {
            if ($tracker->getId() === $this->trackerId) {
                return $tracker;
            }
        }

        return null;
    }

    /**
     * Changing the tracker changes the form: start it afresh.
     */
    public function onTrackerChanged(): void
    {
        $this->resetForm();
    }

    #[LiveAction]
    public function save(CommandBus $commands, TranslatorInterface $translator): ?Response
    {
        $tracker = $this->getTracker();
        if (null === $tracker) {
            return null;
        }

        $this->submitForm();
        $data = $this->getForm()->getData();
        \assert($data instanceof IssueData);
        $viewer = $this->viewer();

        try {
            $issue = $commands->dispatch(new CreateIssue(
                actorId: (int) $viewer->getId(),
                projectId: (int) $this->project->getId(),
                trackerId: (int) $tracker->getId(),
                title: (string) $data->title,
                description: (string) $data->description,
                criticality: $data->criticality,
                assigneeId: $data->assignee?->getId(),
                private: $data->private,
                fields: array_filter($data->fields, static fn (mixed $value): bool => null !== $value && '' !== $value),
            ));
        } catch (IssueException) {
            $this->failure = $translator->trans('issue.create.refused');

            return null;
        }
        \assert($issue instanceof Issue);

        $this->addFlash('success', $translator->trans('issue.create.created', ['%key%' => $issue->getKey()]));

        return $this->redirectToRoute('app_issue_show', [
            'workspaceKey' => $this->project->getWorkspace()->getKey(),
            'projectKey' => $this->project->getKey(),
            'number' => $issue->getNumber(),
        ]);
    }

    /**
     * Without a tracker there is nothing to fill in yet, but the trait
     * always wants a form: an empty one, under the same name. The form
     * only ever travels through the live endpoint, whose required custom
     * header already keeps cross-origin requests out, so it carries no
     * CSRF token of its own.
     *
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $tracker = $this->getTracker();
        if (null === $tracker) {
            return $this->forms->createNamed('issue', FormType::class, null, ['csrf_protection' => false]);
        }

        $viewer = $this->viewer();
        $definition = $this->config->definition();

        return $this->forms->createNamed('issue', IssueType::class, new IssueData(), [
            'workflow' => $definition->workflow($tracker->getWorkflowId()),
            'definition' => $definition,
            'internal' => $viewer->isInternal(),
            'criticality' => $this->access->isGranted($viewer, Permission::IssueSetCriticality, $this->project),
            'members' => $this->access->isGranted($viewer, Permission::IssueAssign, $this->project) ? $this->queries->ask(new ListProjectMembers($this->project)) : null,
            // Issues of a private tracker are private whatever one chooses.
            'private' => !$tracker->isPrivate() && $this->access->isGranted($viewer, Permission::IssueSetPrivate, $this->project),
            'csrf_protection' => false,
        ]);
    }

    /**
     * Field values are kept in sync without re-rendering: nothing on the
     * form depends on them, only on the tracker.
     */
    private function getDataModelValue(): string
    {
        return 'norender|*';
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The new issue form is only rendered for signed-in users.');
    }
}

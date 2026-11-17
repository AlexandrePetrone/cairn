<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\Workflow\Fixture\Subject;
use App\Workflow\WorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class WorkflowFactoryTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private WorkflowInterface $workflow;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->workflow = (new WorkflowFactory($this->dispatcher))->create(DefinitionFactory::bugWorkflow(), DefinitionFactory::placeRow(...));
    }

    public function testTheWorkflowIsNamedAfterTheDefinition(): void
    {
        self::assertSame('bug', $this->workflow->getName());
        self::assertSame(['new', 'in_progress', 'to_test', 'done'], array_keys($this->workflow->getDefinition()->getPlaces()));
    }

    public function testANewSubjectStartsOnTheInitialPlace(): void
    {
        $subject = new Subject();

        self::assertTrue($this->workflow->getMarking($subject)->has('new'));
        self::assertSame('new', $subject->getPlace());
    }

    public function testOnlyTransitionsLeavingTheCurrentPlaceAreEnabled(): void
    {
        $subject = new Subject('to_test');

        self::assertSame(['accept', 'refuse'], self::names($this->workflow->getEnabledTransitions($subject)));
        self::assertTrue($this->workflow->can($subject, 'accept'));
        self::assertFalse($this->workflow->can($subject, 'start'));
    }

    public function testApplyingATransitionHandsTheSubjectTheTargetPlaceDefinition(): void
    {
        $subject = new Subject('new');

        $this->workflow->apply($subject, 'start');

        self::assertSame('in_progress', $subject->getPlace());
        self::assertNotNull($subject->lastPlace);
        self::assertSame(PlaceStatus::InProgress, $subject->lastPlace->getStatus());
        self::assertSame('In progress', $subject->lastPlace->getLabel());
    }

    public function testApplyingAnUnavailableTransitionFails(): void
    {
        $subject = new Subject('new');

        $this->expectException(NotEnabledTransitionException::class);
        $this->workflow->apply($subject, 'accept');
    }

    public function testATransitionWithSeveralSourcesKeepsOneName(): void
    {
        $workflow = (new WorkflowFactory($this->dispatcher))->create(new WorkflowDefinition(
            id: 'w',
            label: 'W',
            initial: 'a',
            places: ['a', 'b', 'c'],
            transitions: [
                new TransitionDefinition(id: 'close', label: 'Close', from: ['a', 'b'], to: 'c', roles: ['developer']),
            ],
        ), DefinitionFactory::placeRow(...));

        self::assertCount(2, $workflow->getDefinition()->getTransitions(), 'one state machine transition per source');
        self::assertSame(['close'], self::names($workflow->getEnabledTransitions(new Subject('a'))));
        self::assertSame(['close'], self::names($workflow->getEnabledTransitions(new Subject('b'))));
        self::assertSame([], self::names($workflow->getEnabledTransitions(new Subject('c'))));
    }

    public function testWorkflowEventsAreDispatched(): void
    {
        $completed = [];
        $this->dispatcher->addListener('workflow.bug.completed', static function (CompletedEvent $event) use (&$completed): void {
            $completed[] = $event->getTransition()?->getName();
        });
        $this->dispatcher->addListener('workflow.bug.guard.accept', static function (GuardEvent $event): void {
            $event->setBlocked(true, 'Not now.');
        });

        $subject = new Subject('to_test');

        self::assertFalse($this->workflow->can($subject, 'accept'));
        self::assertSame(['Not now.'], array_map(static fn ($blocker) => $blocker->getMessage(), iterator_to_array($this->workflow->buildTransitionBlockerList($subject, 'accept'))));

        $this->workflow->apply($subject, 'refuse');

        self::assertSame(['refuse'], $completed);
        self::assertSame('in_progress', $subject->getPlace());
    }

    /**
     * @param iterable<Transition> $transitions
     *
     * @return list<string>
     */
    private static function names(iterable $transitions): array
    {
        $names = [];
        foreach ($transitions as $transition) {
            $names[] = $transition->getName();
        }

        return $names;
    }
}

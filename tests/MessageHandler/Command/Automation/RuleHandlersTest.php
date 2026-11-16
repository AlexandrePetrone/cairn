<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Automation;

use App\Automation\AutomationException;
use App\Automation\Definition\RuleChecker;
use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Automation\DeleteRule;
use App\Message\Command\Automation\UpdateRule;
use App\Message\CommandBus;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * CreateRule, UpdateRule and DeleteRule: what a rule may say on a
 * project, and who writes it. Thomas manages the shop, Nadia develops.
 */
final class RuleHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private const array COMMENT = ['type' => 'comment', 'params' => ['body' => 'Noted.']];

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    public function testARuleKeepsOnlyWhatItsStepsAskFor(): void
    {
        $rule = $this->create(['trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30, 'noise' => true]], 'actions' => [self::COMMENT]]);

        self::assertSame(
            ['trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30]], 'conditions' => [], 'actions' => [self::COMMENT]],
            $rule->getDefinition()->toArray(),
        );
        self::assertTrue($rule->isActive());
        self::assertNull($rule->getTracker());
    }

    public function testARuleNeedsSomethingToDo(): void
    {
        $this->expectExceptionObject(AutomationException::noAction());
        $this->create(['trigger' => ['type' => 'issue_created'], 'actions' => []]);
    }

    public function testARuleHoldsABoundedNumberOfSteps(): void
    {
        $this->expectExceptionObject(AutomationException::tooManySteps(RuleChecker::MAX_STEPS));
        $this->create(['trigger' => ['type' => 'issue_created'], 'actions' => array_fill(0, RuleChecker::MAX_STEPS + 1, self::COMMENT)]);
    }

    public function testAPlaceOfAWorkflowNeedsTheTracker(): void
    {
        $move = ['trigger' => ['type' => 'issue_created'], 'actions' => [['type' => 'move', 'params' => ['place' => 'done']]]];
        self::assertSame('done', $this->create($move, (int) $this->fixtures->bugs->getId())->getDefinition()->actions[0]->text('place'));

        $this->expectExceptionObject(AutomationException::needsTracker());
        $this->create($move);
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rulesThatMeanNothing')]
    public function testAValueMustMeanSomethingOnTheProject(array $definition, string $step, string $parameter): void
    {
        $this->expectExceptionObject(AutomationException::invalidValue($step, $parameter));
        $this->create($definition, (int) $this->fixtures->bugs->getId());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function rulesThatMeanNothing(): iterable
    {
        $created = ['type' => 'issue_created'];

        yield 'a place of no workflow' => [['trigger' => $created, 'actions' => [['type' => 'move', 'params' => ['place' => 'nowhere']]]], 'move', 'place'];
        yield 'a role of no configuration' => [['trigger' => $created, 'actions' => [['type' => 'assign', 'params' => ['target' => 'referent:boss']]]], 'assign', 'target'];
        yield 'someone who is not a member' => [['trigger' => $created, 'actions' => [['type' => 'assign', 'params' => ['target' => 'user:999999999']]]], 'assign', 'target'];
        yield 'nobody cannot follow' => [['trigger' => $created, 'actions' => [['type' => 'subscribe', 'params' => ['target' => 'nobody']]]], 'subscribe', 'target'];
        yield 'a place among several that is of no workflow' => [['trigger' => $created, 'conditions' => [['type' => 'place', 'params' => ['is' => ['new', 'nowhere']]]], 'actions' => [self::COMMENT]], 'place', 'is'];
        yield 'no place at all' => [['trigger' => $created, 'conditions' => [['type' => 'place', 'params' => ['is' => []]]], 'actions' => [self::COMMENT]], 'place', 'is'];
        yield 'a criticality among several that does not exist' => [['trigger' => $created, 'conditions' => [['type' => 'criticality', 'params' => ['is' => ['high', 'urgent']]]], 'actions' => [self::COMMENT]], 'criticality', 'is'];
        yield 'no days' => [['trigger' => ['type' => 'inactive_for', 'params' => ['days' => 0]], 'actions' => [self::COMMENT]], 'inactive_for', 'days'];
        yield 'an empty comment' => [['trigger' => $created, 'actions' => [['type' => 'comment', 'params' => ['body' => '  ']]]], 'comment', 'body'];
        yield 'an unknown choice' => [['trigger' => $created, 'conditions' => [['type' => 'assignee', 'params' => ['is' => 'someone']]], 'actions' => [self::COMMENT]], 'assignee', 'is'];
        yield 'an action of another workflow' => [['trigger' => ['type' => 'transition_applied', 'params' => ['transition' => 'publish']], 'actions' => [self::COMMENT]], 'transition_applied', 'transition'];
    }

    public function testAConditionWrittenWithOneValueStillReads(): void
    {
        $rule = $this->create(['trigger' => ['type' => 'issue_created'], 'conditions' => [['type' => 'criticality', 'params' => ['is' => 'high']]], 'actions' => [self::COMMENT]]);

        self::assertSame(['high'], $rule->getDefinition()->conditions[0]->texts('is'), 'a rule written before the condition took several');
    }

    public function testNobodyActsWhenTimePasses(): void
    {
        $this->expectExceptionObject(AutomationException::nobodyActs());
        $this->create([
            'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30]],
            'conditions' => [['type' => 'actor', 'params' => ['is' => 'external']]],
            'actions' => [self::COMMENT],
        ]);
    }

    public function testARuleIsRenamedSwitchedOffAndRewritten(): void
    {
        $rule = $this->create(['trigger' => ['type' => 'issue_created'], 'actions' => [self::COMMENT]]);
        $id = (int) $rule->getId();
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new UpdateRule($id, name: ' Welcome ', active: false, actorId: $manager));
        self::assertSame('Welcome', $rule->getName());
        self::assertFalse($rule->isActive());

        $this->commands->dispatch(new UpdateRule($id, trackerId: (int) $this->fixtures->bugs->getId(), definition: ['trigger' => ['type' => 'issue_created'], 'actions' => [['type' => 'move', 'params' => ['place' => 'in_progress']]]], actorId: $manager));
        self::assertSame($this->fixtures->bugs->getId(), $rule->getTracker()?->getId());

        try {
            $this->commands->dispatch(new UpdateRule($id, trackerId: UpdateRule::NO_TRACKER, actorId: $manager));
            self::fail('a rule that names a place cannot leave its tracker');
        } catch (AutomationException $e) {
            self::assertEquals(AutomationException::needsTracker(), $e);
        }
    }

    public function testOnlyWhoConfiguresTheProjectWritesItsRules(): void
    {
        $rule = $this->create(['trigger' => ['type' => 'issue_created'], 'actions' => [self::COMMENT]]);

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new DeleteRule((int) $rule->getId(), (int) $this->fixtures->developer->getId()));
    }

    public function testADeletedRuleLeavesItsExecutions(): void
    {
        $rule = $this->create(['trigger' => ['type' => 'issue_created'], 'actions' => [self::COMMENT]], name: 'Welcome');
        $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new DeleteRule((int) $rule->getId(), (int) $this->fixtures->manager->getId()));
        $this->entityManager->clear();

        self::assertSame([], $this->entityManager->getRepository(AutomationRule::class)->findAll());
        $runs = $this->entityManager->getRepository(AutomationRun::class)->findAll();
        self::assertCount(1, $runs);
        self::assertNull($runs[0]->getRule());
        self::assertSame('Welcome', $runs[0]->getRuleName());
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function create(array $definition, ?int $trackerId = null, string $name = 'A rule'): AutomationRule
    {
        return $this->commands->dispatch(new CreateRule((int) $this->fixtures->access->shop->getId(), $name, $trackerId, $definition, actorId: (int) $this->fixtures->manager->getId()));
    }
}

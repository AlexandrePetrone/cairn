<?php

declare(strict_types=1);

namespace App\Tests\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\Target;
use App\Automation\Definition\TriggerType;
use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Comment;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\RunOutcome;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Membership;
use App\Entity\PendingNotification;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Budget\AddBudgetLine;
use App\Message\Command\Budget\CreateBudget;
use App\Message\Command\Budget\UpdateBudget;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Membership\SetReferent;
use App\Message\Command\Project\UpdateProject;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\MessageHandler\Notification\NotifyIssueChangeHandler;
use App\Notification\SubscriptionRules;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The automation rules of a project, triggered by the real commands.
 * Thomas manages the shop, Nadia develops, Claire is the client; the
 * `bug` workflow goes new, in_progress, to_test, done.
 */
final class RuleEngineTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private EntityManagerInterface $entityManager;
    private ?User $accountant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    public function testANewIssueGoesToTheReferentManager(): void
    {
        $this->referent($this->fixtures->manager);
        $rule = $this->newIssuesToTheManager();

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertSame($this->fixtures->manager->getId(), $this->assigneeId($issue));
        $entry = $this->lastEntry($issue, JournalEntryType::Changed);
        self::assertNull($entry->getAuthor(), 'a rule acts without an author');
        self::assertSame('New issue to the manager', $entry->getRule());
        self::assertSame('Thomas Test', $entry->text('to'));

        $runs = $this->runs($rule);
        self::assertCount(1, $runs);
        self::assertSame(RunOutcome::Applied, $runs[0]->getOutcome());
        self::assertSame(TriggerType::IssueCreated->value, $runs[0]->getTrigger());
        self::assertSame($this->fixtures->client->getId(), $runs[0]->getActor()?->getId());
        self::assertSame([['action' => 'assign', 'result' => 'applied', 'value' => 'Thomas Test']], $runs[0]->getSteps());
        self::assertTrue(self::getContainer()->get(SubscriptionRules::class)->isSubscribed($issue, $this->fixtures->manager));
    }

    public function testAnIssueCreatedWithAnAssigneeIsLeftAlone(): void
    {
        $this->referent($this->fixtures->manager);
        $rule = $this->newIssuesToTheManager();

        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->developer->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Mine',
            description: 'I take it.',
            fields: ['environment' => 'prod'],
            assigneeId: (int) $this->fixtures->developer->getId(),
        ));

        self::assertSame($this->fixtures->developer->getId(), $this->assigneeId($issue));
        self::assertSame([], $this->runs($rule), 'a rule whose conditions do not hold leaves nothing');
    }

    public function testWithoutAReferentTheRuleIsSkippedAndSaysWhy(): void
    {
        $rule = $this->newIssuesToTheManager();

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertNull($issue->getAssignee(), 'nobody is the referent until designated');
        $runs = $this->runs($rule);
        self::assertSame(RunOutcome::Skipped, $runs[0]->getOutcome());
        self::assertSame([['action' => 'assign', 'result' => 'skipped', 'value' => 'Project manager', 'reason' => 'no_referent']], $runs[0]->getSteps());
    }

    public function testAClientCommentSendsTheIssueBackToTheManager(): void
    {
        $this->referent($this->fixtures->manager);
        $rule = $this->rule(
            'Client comments',
            [TriggerType::CommentAdded, []],
            [[ConditionType::Actor, ['is' => ConditionType::EXTERNAL]], [ConditionType::Assignee, ['is' => ConditionType::EXTERNAL_OR_NOBODY]]],
            [[ActionType::Assign, ['target' => Target::referent('project_manager')]]],
        );
        $manager = (int) $this->fixtures->manager->getId();
        $client = (int) $this->fixtures->client->getId();
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeId: $client));
        $this->commands->dispatch(new AddComment($manager, $issue->getKey(), 'Can you check?'));
        self::assertSame($client, $this->assigneeId($issue), 'an internal user commenting changes nothing');

        $this->commands->dispatch(new AddComment($client, $issue->getKey(), 'Here is the file.'));
        self::assertSame($manager, $this->assigneeId($issue));
        self::assertCount(1, $this->runs($rule));

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeId: (int) $this->fixtures->developer->getId()));
        $this->commands->dispatch(new AddComment($client, $issue->getKey(), 'One more thing.'));
        self::assertSame($this->fixtures->developer->getId(), $this->assigneeId($issue), 'an issue an internal user works on stays theirs');
        self::assertCount(1, $this->runs($rule));
    }

    public function testATargetReadFromTheIssue(): void
    {
        $this->rule(
            'Back to an internal user',
            [TriggerType::CommentAdded, []],
            [[ConditionType::Actor, ['is' => ConditionType::EXTERNAL]]],
            [[ActionType::Assign, ['target' => Target::LAST_INTERNAL]], [ActionType::Subscribe, ['target' => Target::AUTHOR]]],
        );
        $manager = (int) $this->fixtures->manager->getId();
        $developer = (int) $this->fixtures->developer->getId();
        $client = (int) $this->fixtures->client->getId();
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeId: $developer));
        $this->commands->dispatch(new UpdateIssue($developer, $issue->getKey(), assigneeId: $client));

        $this->commands->dispatch(new AddComment($client, $issue->getKey(), 'Done on my side.'));

        self::assertSame($developer, $this->assigneeId($issue), 'Nadia was the last internal user to act on the issue');
        self::assertTrue(self::getContainer()->get(SubscriptionRules::class)->isSubscribed($issue, $this->fixtures->client));
    }

    public function testRulesFollowOneAnotherThreeDeepAtMost(): void
    {
        $bugs = $this->fixtures->bugs;
        $this->rule('1 raise', [TriggerType::IssueCreated, []], [], [[ActionType::SetCriticality, ['criticality' => 'high']]]);
        $this->rule('2 start', [TriggerType::CriticalityChanged, []], [[ConditionType::Criticality, ['is' => 'high']]], [[ActionType::Move, ['place' => 'in_progress']]], $bugs);
        $this->rule('3 say', [TriggerType::PlaceChanged, ['place' => 'in_progress']], [], [[ActionType::Comment, ['body' => 'We are on it.']]]);
        $fourth = $this->rule('4 hide', [TriggerType::CommentAdded, []], [], [[ActionType::MakePrivate, []]]);

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertSame(Criticality::High, $issue->getCriticality());
        self::assertSame('in_progress', $issue->getPlace());
        $comments = $this->entityManager->getRepository(Comment::class)->findBy(['issue' => $issue]);
        self::assertCount(1, $comments);
        self::assertNull($comments[0]->getAuthor());
        self::assertSame('3 say', $comments[0]->getAuthorName());
        self::assertFalse($issue->isPrivate(), 'the fourth rule in a row is not run');
        self::assertSame([RunOutcome::Stopped], array_map(static fn (AutomationRun $run): RunOutcome => $run->getOutcome(), $this->runs($fourth)));
    }

    public function testAStepConditionHoldsOnAnyOfItsPlaces(): void
    {
        $this->rule('Raise what moves', [TriggerType::CommentAdded, []], [[ConditionType::Place, ['is' => ['in_progress', 'to_test']]]], [[ActionType::SetCriticality, ['criticality' => 'high']]]);
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $developer = (int) $this->fixtures->developer->getId();

        $this->commands->dispatch(new AddComment($developer, $issue->getKey(), 'Not started.'));
        self::assertSame(Criticality::Normal, $issue->getCriticality(), 'the issue is on neither place');

        $this->commands->dispatch(new ApplyTransition($developer, $issue->getKey(), 'start'));
        $this->commands->dispatch(new AddComment($developer, $issue->getKey(), 'On it.'));
        self::assertSame(Criticality::High, $issue->getCriticality());
    }

    public function testACriticalityConditionHoldsOnAnyOfItsValues(): void
    {
        $this->rule('Hide what is urgent', [TriggerType::CommentAdded, []], [[ConditionType::Criticality, ['is' => ['high', 'blocking']]]], [[ActionType::MakePrivate, []]]);
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new AddComment($manager, $issue->getKey(), 'Normal for now.'));
        self::assertFalse($issue->isPrivate(), 'normal is neither');

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), criticality: Criticality::Blocking));
        $this->commands->dispatch(new AddComment($manager, $issue->getKey(), 'Now it is.'));
        self::assertTrue($issue->isPrivate());
    }

    public function testAnExecutionIsPartialWhenSomeActionsCouldNotBeDone(): void
    {
        $rule = $this->rule('Half', [TriggerType::IssueCreated, []], [], [
            [ActionType::SetCriticality, ['criticality' => 'high']],
            [ActionType::Assign, ['target' => Target::referent('project_manager')]],
        ]);

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertSame(Criticality::High, $issue->getCriticality());
        self::assertSame(RunOutcome::Partial, $this->runs($rule)[0]->getOutcome(), 'the criticality changed, nobody was the referent');
    }

    public function testARuleThatFailsDoesNotUndoWhatSomeoneDid(): void
    {
        // A listener breaks on what the rule records, as a bug would.
        $rule = $this->rule(BrokenCommentListener::RULE, [TriggerType::CommentAdded, []], [], [
            [ActionType::Comment, ['body' => 'Noted.']],
            [ActionType::SetCriticality, ['criticality' => 'high']],
        ]);
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $comment = $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'Here is the file.'));

        self::assertNotNull($comment->getId(), 'what the client wrote is kept');
        self::assertSame(Criticality::High, $issue->getCriticality(), 'the next action went on');
        $run = $this->runs($rule)[0];
        self::assertSame(RunOutcome::Partial, $run->getOutcome());
        self::assertSame(['action' => 'comment', 'result' => 'skipped', 'reason' => 'error'], $run->getSteps()[0]);
    }

    public function testARuleRunsOnceForWhatSomeoneDid(): void
    {
        // Both react to a comment: the second also sees the comment the first writes.
        $this->rule('Echo', [TriggerType::CommentAdded, []], [], [[ActionType::Comment, ['body' => 'Echo.']]]);
        $this->rule('Thanks', [TriggerType::CommentAdded, []], [], [[ActionType::Comment, ['body' => 'Thanks.']]]);
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'Hello'));

        $signatures = array_map(static fn (Comment $comment): string => (string) $comment->getSignature(), $this->entityManager->getRepository(Comment::class)->findBy(['issue' => $issue, 'author' => null], ['id' => 'ASC']));
        self::assertSame(['Echo', 'Thanks'], $signatures, 'neither rule answers the other, nor twice');

        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'Hello again'));
        self::assertCount(4, $this->entityManager->getRepository(Comment::class)->findBy(['issue' => $issue, 'author' => null]), 'another thing someone did is another chain');
    }

    public function testARuleTellsTheFollowersUnderItsName(): void
    {
        $this->rule('Welcome', [TriggerType::IssueCreated, []], [], [[ActionType::Comment, ['body' => 'Thank you, we received your issue.']]]);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);

        $this->fixtures->createIssue($this->fixtures->client);

        $messages = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $async->getSent()), static fn (object $message): bool => $message instanceof NotifyIssueChange));
        self::assertCount(2, $messages, 'the creation, then the comment of the rule');
        [$creation, $welcome] = $messages;
        self::assertSame($this->fixtures->client->getId(), $creation->actorId);
        self::assertNull($welcome->actorId);
        self::assertSame('Welcome', $welcome->rule);

        self::getContainer()->get(NotifyIssueChangeHandler::class)($welcome);
        $waiting = $this->entityManager->getRepository(PendingNotification::class)->findAll();
        $recipients = array_map(static fn (PendingNotification $row): string => $row->getRecipient()->getEmail(), $waiting);
        sort($recipients);
        self::assertSame(['claire@client.test', 'thomas@agency.test'], $recipients, 'a rule tells everyone who follows, the person who triggered it included');
        self::assertSame('Welcome', $waiting[0]->getRule());
        self::assertNull($waiting[0]->getActor());
    }

    public function testAnActionOfTheWorkflowMarksTheBudgetLinesOfTheIssue(): void
    {
        $rule = $this->startApprovesWhatWaits();
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $other = $this->fixtures->createIssue($this->fixtures->client, title: 'Another one');
        $budget = $this->budget('Order 2026-01');
        $closed = $this->budget('Order 2025-06');
        $pending = $this->line($budget, BudgetLineStatus::Pending, 840, $issue);
        $refused = $this->line($budget, BudgetLineStatus::Refused, 420, $issue);
        $elsewhere = $this->line($budget, BudgetLineStatus::Pending, 420, $other);
        $past = $this->line($closed, BudgetLineStatus::Pending, 420, $issue);
        $this->commands->dispatch(new UpdateBudget($this->accountant(), (int) $closed->getId(), closed: true));
        $journal = $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $issue]);

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start'));

        self::assertSame([BudgetLineStatus::Approved, 840], [$pending->getStatus(), $pending->getQuantity()], 'the line that waited is approved as it was estimated');
        self::assertSame(BudgetLineStatus::Refused, $refused->getStatus(), 'a status the rule does not start from is left alone');
        self::assertSame(BudgetLineStatus::Pending, $elsewhere->getStatus(), 'and so is the line of another issue');
        self::assertSame(BudgetLineStatus::Pending, $past->getStatus(), 'and the line of a closed budget');
        $runs = $this->runs($rule);
        self::assertCount(1, $runs);
        self::assertSame(RunOutcome::Applied, $runs[0]->getOutcome());
        self::assertSame([['action' => 'mark_budget_lines', 'result' => 'applied', 'value' => 'approved']], $runs[0]->getSteps());
        self::assertSame($journal + 1, $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $issue]), 'the history of the issue only holds the action someone applied');
    }

    public function testARuleKeepsTheQuantityOfTheLinesItSendsBackToEstimate(): void
    {
        $this->rule('Start again', [TriggerType::CommentAdded, []], [], [[ActionType::MarkBudgetLines, ['status' => 'to_estimate', 'from' => ['pending', 'approved']]]]);
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $line = $this->line($this->budget('Order 2026-01'), BudgetLineStatus::Approved, 840, $issue);

        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'This is not what we asked.'));

        self::assertSame([BudgetLineStatus::ToEstimate, 840], [$line->getStatus(), $line->getQuantity()]);
    }

    public function testARuleDoesNotEstimateALine(): void
    {
        $rule = $this->startApprovesWhatWaits(from: ['to_estimate']);
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $unbudgeted = $this->fixtures->createIssue($this->fixtures->client, title: 'Nothing to pay');
        $line = $this->line($this->budget('Order 2026-01'), BudgetLineStatus::ToEstimate, null, $issue);

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start'));
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $unbudgeted->getKey(), 'start'));

        self::assertSame(BudgetLineStatus::ToEstimate, $line->getStatus(), 'approved, a line has a quantity');
        $runs = $this->runs($rule);
        self::assertSame([['action' => 'mark_budget_lines', 'result' => 'skipped', 'value' => 'approved', 'reason' => 'no_quantity']], $runs[0]->getSteps());
        self::assertSame([['action' => 'mark_budget_lines', 'result' => 'unchanged', 'value' => 'approved']], $runs[1]->getSteps(), 'an issue no line names has nothing to mark');
    }

    public function testOnAProjectWithoutBudgetsTheLinesAreLeftAlone(): void
    {
        $rule = $this->startApprovesWhatWaits();
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $line = $this->line($this->budget('Order 2026-01'), BudgetLineStatus::Pending, 840, $issue);
        $this->commands->dispatch(new UpdateProject((int) $this->fixtures->access->shop->getId(), withoutBudgets: true));

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start'));

        self::assertSame(BudgetLineStatus::Pending, $line->getStatus());
        self::assertSame([['action' => 'mark_budget_lines', 'result' => 'skipped', 'reason' => 'no_budget']], $this->runs($rule)[0]->getSteps());
    }

    /**
     * @param list<string> $from
     */
    private function startApprovesWhatWaits(array $from = ['pending']): AutomationRule
    {
        return $this->rule(
            'Starting approves the estimate',
            [TriggerType::TransitionApplied, ['transition' => 'start']],
            [],
            [[ActionType::MarkBudgetLines, ['status' => 'approved', 'from' => $from]]],
            $this->fixtures->bugs,
        );
    }

    /** An open budget of the shop, in days. No role of these fixtures manages the budgets: an administrator writes them. */
    private function budget(string $name): Budget
    {
        $shop = (int) $this->fixtures->access->shop->getId();
        $this->commands->dispatch(new UpdateProject($shop, budgetUnit: BudgetUnit::Days));

        return $this->commands->dispatch(new CreateBudget($this->accountant(), $shop, $name, 4200));
    }

    private function accountant(): int
    {
        $this->accountant ??= $this->fixtures->access->user('accountant@agency.test', internal: true, admin: true);

        return (int) $this->accountant->getId();
    }

    private function line(Budget $budget, BudgetLineStatus $status, ?int $minutes, Issue $issue): BudgetLine
    {
        return $this->commands->dispatch(new AddBudgetLine($this->accountant(), (int) $budget->getId(), $status, $minutes, $issue->getId()));
    }

    /** Read through a method: the issue changes under the commands, which an assertion on its getter would hide from the analysis. */
    private function assigneeId(Issue $issue): ?int
    {
        return $issue->getAssignee()?->getId();
    }

    private function newIssuesToTheManager(): AutomationRule
    {
        return $this->rule(
            'New issue to the manager',
            [TriggerType::IssueCreated, []],
            [[ConditionType::Assignee, ['is' => ConditionType::NOBODY]]],
            [[ActionType::Assign, ['target' => Target::referent('project_manager')]]],
        );
    }

    /**
     * @param array{TriggerType, array<string, mixed>}         $trigger
     * @param list<array{ConditionType, array<string, mixed>}> $conditions
     * @param list<array{ActionType, array<string, mixed>}>    $actions
     */
    private function rule(string $name, array $trigger, array $conditions, array $actions, ?Tracker $tracker = null): AutomationRule
    {
        $step = static fn (array $step): array => ['type' => $step[0]->value, 'params' => $step[1]];

        return $this->commands->dispatch(new CreateRule(
            (int) $this->fixtures->access->shop->getId(),
            $name,
            $tracker?->getId(),
            ['trigger' => $step($trigger), 'conditions' => array_map($step, $conditions), 'actions' => array_map($step, $actions)],
        ));
    }

    private function referent(User $user): void
    {
        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy(['user' => $user]);
        self::assertNotNull($membership);
        $this->commands->dispatch(new SetReferent((int) $membership->getId(), true));
    }

    /**
     * @return list<AutomationRun>
     */
    private function runs(AutomationRule $rule): array
    {
        return array_values($this->entityManager->getRepository(AutomationRun::class)->findBy(['rule' => $rule], ['id' => 'ASC']));
    }

    private function lastEntry(Issue $issue, JournalEntryType $type): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => $type], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }
}

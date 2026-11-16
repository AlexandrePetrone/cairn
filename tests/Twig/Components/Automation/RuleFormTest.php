<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Automation;

use App\Entity\AutomationRule;
use App\Message\Command\Automation\CreateRule;
use App\Message\CommandBus;
use App\Repository\AutomationRuleRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The editor of an automation rule, on the shop of the scene: Thomas
 * manages it; its trackers are Bug (workflow `bug`, with the place
 * "Info requested") and Feature (workflow `evolution`).
 */
final class RuleFormTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testANewRuleIsWrittenRowByRowAndReadInOneSentence(): void
    {
        $component = $this->component();
        $render = (string) $component->render();
        self::assertStringContainsString('When an issue is created: assign it to ….', $render);

        $component->set('automation_rule.name', 'New issue to the manager')->set('automation_rule.actions.0.params.target', 'referent:project_manager');
        $render = (string) $component->render();
        self::assertStringContainsString('When an issue is created: assign it to the Project manager referent.', $render);
        self::assertStringContainsString('Nobody today', $render, 'the shop has no referent yet');

        $component->call('addCondition')->set('automation_rule.conditions.0.params.is', 'nobody');
        self::assertStringContainsString('When an issue is created, if it is assigned to nobody: assign it to the Project manager referent.', (string) $component->render());

        $component->call('save');
        self::assertSame(302, $component->response()->getStatusCode());
        $rules = $this->rules();
        self::assertCount(1, $rules);
        self::assertSame('New issue to the manager', $rules[0]->getName());
        self::assertSame([
            'trigger' => ['type' => 'issue_created', 'params' => []],
            'conditions' => [['type' => 'assignee', 'params' => ['is' => 'nobody']]],
            'actions' => [['type' => 'assign', 'params' => ['target' => 'referent:project_manager']]],
        ], $rules[0]->getDefinition()->toArray());
    }

    public function testTheFieldsOfARowFollowItsTypeAndTheTracker(): void
    {
        $component = $this->component();

        $render = (string) $component->set('automation_rule.actions.0.type', 'move')->render();
        self::assertStringContainsString('Limit the rule to a category to choose here.', $render);
        self::assertStringNotContainsString('automation_rule[actions][0][params][target]', $render, 'the value of the type the row had is gone');

        $render = (string) $component->set('automation_rule.tracker', (string) $this->scene->bugs->getId())->render();
        self::assertStringContainsString('<option value="needs_info"', $render);
        $component->set('automation_rule.actions.0.params.place', 'needs_info');
        self::assertStringContainsString('move it to “Info requested”', (string) $component->render());

        $render = (string) $component->set('automation_rule.tracker', (string) $this->scene->evolutions->getId())->render();
        self::assertStringNotContainsString('<option value="needs_info"', $render, 'the places are those of the tracker');
        self::assertStringContainsString('move it to “…”', $render, 'a place of another workflow does not stay');

        $render = (string) $component->set('automation_rule.trigger.type', 'inactive_for')->set('automation_rule.trigger.params.days', '30')->render();
        self::assertStringContainsString('name="automation_rule[trigger][params][days]"', $render);
        self::assertStringContainsString('After 30 days without activity', $render);

        $render = (string) $component->call('addAction')->call('removeAction', ['index' => 0])->render();
        self::assertStringContainsString('name="automation_rule[actions][0][params][target]"', $render, 'the row added took the place of the one removed');
        self::assertStringNotContainsString('automation_rule[actions][1]', $render);
    }

    public function testAStepIsChosenAmongSeveralWhateverTheWorkflow(): void
    {
        $component = $this->component();
        $render = (string) $component->call('addCondition')->set('automation_rule.conditions.0.type', 'place')->render();
        self::assertSame(1, substr_count($render, 'value="in_progress"'), 'three workflows have "In progress": it is offered once');
        self::assertStringNotContainsString('<legend', $render, 'no workflow is named');

        $component->set('automation_rule.conditions.0.params.is', ['needs_info', 'to_approve']);
        self::assertStringContainsString('if it is at the step “Info requested” or “Estimate to approve”', (string) $component->render());

        $render = (string) $component->set('automation_rule.tracker', (string) $this->scene->bugs->getId())->render();
        self::assertStringNotContainsString('value="to_approve"', $render, 'limited to a tracker, only its places are offered');
        self::assertStringContainsString('if it is at the step “Info requested”:', $render, 'and the place of the other workflow is gone');

        $component->set('automation_rule.name', 'Waiting')->set('automation_rule.actions.0.params.target', 'author')->call('save');
        self::assertSame(['needs_info'], $this->rules()[0]->getDefinition()->conditions[0]->params['is']);
    }

    public function testACriticalityIsChosenAmongSeveral(): void
    {
        $component = $this->component();
        $render = (string) $component->call('addCondition')->set('automation_rule.conditions.0.type', 'criticality')->render();
        self::assertMatchesRegularExpression('~type="checkbox"\s+value="blocking"[^>]*name="automation_rule\[conditions\]\[0\]\[params\]\[is\]\[\]"~', $render, 'one box per criticality');
        self::assertStringNotContainsString('<legend', explode('automation_rule[conditions][0][params][is]', $render, 2)[1] ?? '', 'criticalities are not grouped');

        $component->set('automation_rule.conditions.0.params.is', ['high', 'blocking']);
        self::assertStringContainsString('if its criticality is high or blocking:', (string) $component->render());

        $component->set('automation_rule.name', 'Urgent')->set('automation_rule.actions.0.params.target', 'author')->call('save');
        self::assertSame(['high', 'blocking'], $this->rules()[0]->getDefinition()->conditions[0]->params['is']);
    }

    public function testTwoPeopleWithTheSameNameAreTwoChoices(): void
    {
        $nadia = $this->scene->user('nadia.benali@cairn.test');
        $lea = $this->scene->user('lea.fontaine@cairn.test');
        $lea->rename($nadia->getFirstName(), $nadia->getLastName());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $render = (string) $this->component()->render();

        self::assertStringContainsString('<option value="user:'.$nadia->getId().'">Nadia Benali</option>', $render);
        self::assertStringContainsString('<option value="user:'.$lea->getId().'">Nadia Benali</option>', $render);
    }

    public function testTheEditorIsDrawnOnlyForWhoConfiguresTheProject(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Automation:RuleForm', ['project' => $this->scene->shop, 'rule' => null])->actingAs($this->scene->user('nadia.benali@cairn.test'))->render();
    }

    public function testAnIncompleteRuleIsNotSaved(): void
    {
        $component = $this->component()->set('automation_rule.name', 'Half a rule');

        try {
            $component->call('save');
            self::fail('an action without its target is not a rule');
        } catch (UnprocessableEntityHttpException) {
            self::assertSame([], $this->rules());
        }
    }

    public function testARuleIsEditedThenDeletedAfterAConfirmation(): void
    {
        $rule = self::getContainer()->get(CommandBus::class)->dispatch(new CreateRule(
            (int) $this->scene->shop->getId(),
            'Reminder',
            null,
            ['trigger' => ['type' => 'inactive_for', 'params' => ['days' => 30]], 'actions' => [['type' => 'comment', 'params' => ['body' => 'Any news?']]]],
        ));
        self::assertInstanceOf(AutomationRule::class, $rule);
        $component = $this->component($rule);
        $render = (string) $component->render();
        self::assertStringContainsString('value="Reminder"', $render);
        self::assertStringContainsString('Any news?</textarea>', $render);

        $component->set('automation_rule.trigger.params.days', '45')->set('automation_rule.actions.0.params.private', true)->call('save');
        self::assertSame(302, $component->response()->getStatusCode());
        $saved = $this->rules()[0]->getDefinition();
        self::assertSame(45, $saved->trigger->params['days']);
        self::assertTrue($saved->actions[0]->flag('private'));

        $component = $this->component($this->rules()[0]);
        self::assertStringContainsString('Delete this rule?', (string) $component->call('confirmDelete')->render());
        $component->call('delete');
        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame([], $this->rules());
    }

    private function component(?AutomationRule $rule = null): TestLiveComponent
    {
        return $this->createLiveComponent('Automation:RuleForm', ['project' => $this->scene->shop, 'rule' => $rule])->actingAs($this->scene->user('thomas.girard@cairn.test'));
    }

    /**
     * @return list<AutomationRule>
     */
    private function rules(): array
    {
        return self::getContainer()->get(AutomationRuleRepository::class)->findByProject($this->scene->shop);
    }
}

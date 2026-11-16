<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Template;

use App\Entity\ProjectTemplate;
use App\Entity\TemplateRule;
use App\Entity\TemplateTracker;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Message\CommandBus;
use App\Repository\TemplateRuleRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The editor of a rule of a project template: the editor of a rule of a
 * project, with the trackers of the template and nobody to name. Julie
 * administers the instance, Thomas manages a project.
 */
final class RuleFormTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private ProjectTemplate $template;
    private TemplateTracker $bugs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $commands = self::getContainer()->get(CommandBus::class);
        $template = $commands->dispatch(new CreateTemplate('Web project'));
        \assert($template instanceof ProjectTemplate);
        $this->template = $template;
        $bugs = $commands->dispatch(new CreateTemplateTracker((int) $template->getId(), 'Bug', 'bug'));
        \assert($bugs instanceof TemplateTracker);
        $this->bugs = $bugs;
    }

    public function testARuleIsWrittenWithRolesAndTheTrackersOfTheTemplate(): void
    {
        $component = $this->component();
        $render = (string) $component->render();
        self::assertStringContainsString('value="referent:project_manager"', $render);
        self::assertStringNotContainsString('value="user:', $render, 'a template has nobody to name');
        self::assertStringContainsString('href="/admin/templates/'.$this->template->getId().'/automations"', $render, 'Cancel leads back to the rules of the template');

        $component->set('automation_rule.name', 'New issue to the manager')->set('automation_rule.actions.0.params.target', 'referent:project_manager');
        $render = (string) $component->render();
        self::assertStringContainsString('When an issue is created: assign it to the Project manager referent.', $render);
        self::assertStringNotContainsString('Nobody today', $render, 'nobody answers for a role in a template');

        $render = (string) $component->call('addAction')->set('automation_rule.actions.1.type', 'move')->set('automation_rule.tracker', (string) $this->bugs->getId())->render();
        self::assertStringContainsString('<option value="needs_info"', $render, 'the places of the tracker of the template');
        $component->set('automation_rule.actions.1.params.place', 'needs_info')->call('save');
        self::assertSame(302, $component->response()->getStatusCode());

        $rules = $this->rules();
        self::assertCount(1, $rules);
        self::assertSame('New issue to the manager', $rules[0]->getName());
        self::assertSame($this->bugs->getId(), $rules[0]->getTracker()?->getId());
        self::assertSame('needs_info', $rules[0]->getDefinition()->actions[1]->text('place'));
    }

    public function testARuleIsEditedThenDeletedAfterAConfirmation(): void
    {
        $this->component()->set('automation_rule.name', 'Thanks')->set('automation_rule.actions.0.type', 'comment')->set('automation_rule.actions.0.params.body', 'Thank you.')->call('save');
        $component = $this->component($this->rules()[0]);
        self::assertStringContainsString('Thank you.</textarea>', (string) $component->render());

        $component->set('automation_rule.name', 'Thank the author')->call('save');
        self::assertSame('Thank the author', $this->rules()[0]->getName());

        $component = $this->component($this->rules()[0]);
        self::assertStringContainsString('Delete this rule?', (string) $component->call('confirmDelete')->render());
        $component->call('delete');
        self::assertSame([], $this->rules());
    }

    public function testOnlyAnAdministratorOpensTheEditor(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Template:RuleForm', ['projectTemplate' => $this->template])->actingAs($this->scene->user('thomas.girard@cairn.test'))->render();
    }

    private function component(?TemplateRule $rule = null): TestLiveComponent
    {
        return $this->createLiveComponent('Template:RuleForm', ['projectTemplate' => $this->template, 'rule' => $rule])->actingAs($this->scene->user('julie.admin@cairn.test'));
    }

    /**
     * @return list<TemplateRule>
     */
    private function rules(): array
    {
        return self::getContainer()->get(TemplateRuleRepository::class)->findByTemplate($this->template);
    }
}

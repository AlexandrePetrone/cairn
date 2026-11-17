<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Config\ConfigRegistry;
use App\Config\Definition\InstanceDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Factory\UserFactory;
use App\Security\Permission;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;

/**
 * The configuration pages, on the starter configuration the scene
 * installs: four roles, three workflows, the shop's ten issues. What they
 * change is read back from the configuration, as the rest of the
 * application reads it.
 */
final class ConfigurationTest extends WebTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testOnlyAdministratorsReadIt(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $this->client->loginUser($scene->user('thomas.girard@cairn.test'));

        $this->client->request('GET', '/admin/configuration/roles');
        self::assertResponseStatusCodeSame(403);

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Config:PermissionMatrix')->actingAs($scene->user('thomas.girard@cairn.test'))->render();
    }

    public function testTheRolesAndTheirRightsAreListed(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration');
        self::assertResponseRedirects('/admin/configuration/roles');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('aside', 'Roles and rights', 'the configuration has its own section in the navigation');
        self::assertSelectorTextContains('aside', 'Steps');
        self::assertSelectorTextContains('#config-roles-project_manager', 'Project manager');
        self::assertSelectorTextContains('#config-roles-project_manager', 'Internal users only');
        self::assertSelectorTextContains('#config-roles-project_manager', '1 access', 'Thomas on Maison Dupont');

        $assign = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Assign issues'));
        self::assertCount(1, $assign);
        // Project manager, Developer, Client, Client (read-only)
        self::assertSame([true, true, false, false], $assign->filter('input[type=checkbox]')->each(static fn ($box): bool => null !== $box->attr('checked')));

        $delete = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Delete an issue'));
        self::assertCount(2, $delete->filter('input[type=checkbox]'), 'a right for internal roles is not offered to the roles open to external users');
    }

    public function testARightIsTickedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $matrix = $this->createLiveComponent('Config:PermissionMatrix')->actingAs($scene->user('julie.admin@cairn.test'));

        $matrix->call('toggle', ['role' => 'client', 'permission' => Permission::IssueRank->value]);
        self::assertContains('client', $this->definition()->rolesGranted(Permission::IssueRank));

        $matrix->call('toggle', ['role' => 'client', 'permission' => Permission::IssueRank->value]);
        self::assertNotContains('client', $this->definition()->rolesGranted(Permission::IssueRank));

        $matrix->call('toggle', ['role' => 'client', 'permission' => Permission::IssueDelete->value]);
        self::assertStringContainsString('open to external users', $matrix->render()->toString(), 'a refusal is said in place');
    }

    public function testAListIsReorderedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $admin = $scene->user('julie.admin@cairn.test');

        $this->createLiveComponent('Config:Order', ['kind' => 'roles'])->actingAs($admin)->call('move', ['id' => 'client', 'position' => 0]);
        self::assertSame(['client', 'project_manager', 'developer', 'client_readonly'], $this->definition()->roleIds());

        $this->createLiveComponent('Config:Order', ['kind' => 'workflow_places', 'workflow' => 'task'])->actingAs($admin)->call('move', ['id' => 'done', 'position' => 1]);
        self::assertSame(['new', 'done', 'in_progress', 'blocked', 'to_test', 'to_release', 'declined'], $this->definition()->workflow('task')->places);

        $this->createLiveComponent('Config:Order', ['kind' => 'transitions', 'workflow' => 'task'])->actingAs($admin)->call('move', ['id' => 'cancel', 'position' => 0]);
        self::assertSame('cancel', array_key_first($this->definition()->workflow('task')->transitions));
    }

    public function testARoleIsCreatedChangedAndDeletedInADialog(): void
    {
        $this->signInAsAdmin();

        // Asked for the frame, the dialog comes alone.
        $this->client->request('GET', '/admin/configuration/roles/new', server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('aside');
        self::assertSelectorExists('turbo-frame#modal form');

        $this->client->request('GET', '/admin/configuration/roles/new');
        $this->client->submitForm('Create', ['role[label]' => ' ']);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Create', ['role[label]' => 'Auditor', 'role[followsNewIssues]' => true]);
        self::assertResponseRedirects('/admin/configuration/roles');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Role "Auditor" created.');
        self::assertSelectorTextContains('#config-roles-auditor', 'Subscribed to every new issue');

        $this->client->request('GET', '/admin/configuration/roles/auditor');
        $this->client->submitForm('Save', ['role[label]' => 'External auditor', 'role[internalOnly]' => true]);
        $this->client->followRedirect();
        self::assertSame('External auditor', $this->definition()->role('auditor')->label);
        self::assertTrue($this->definition()->role('auditor')->internalOnly);

        $this->client->request('GET', '/admin/configuration/roles/auditor');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Role "External auditor" deleted.');
        self::assertFalse($this->definition()->hasRole('auditor'));
    }

    public function testARefusalIsSaidInTheDialogOrOnThePage(): void
    {
        $this->signInAsAdmin();

        // Claire and Sophie, external, hold the role of client.
        $this->client->request('GET', '/admin/configuration/roles/client');
        $this->client->submitForm('Save', ['role[internalOnly]' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#modal', 'accesses of external users carry the role "Client"');

        $this->client->request('GET', '/admin/configuration/roles/client');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'accesses carry the role "Client"');
        self::assertTrue($this->definition()->hasRole('client'));

        $this->client->request('GET', '/admin/configuration/roles/nobody');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStepIsCreatedAndItsStatusGoesToItsIssues(): void
    {
        $scene = $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/places');
        self::assertSelectorTextContains('#config-places-to_test', 'To test');
        self::assertSelectorTextContains('#config-places-to_test', 'Bug, Feature, Internal task');
        self::assertSelectorTextContains('#config-places-to_test', '1 issue');
        self::assertSelectorTextContains('#config-places-needs_info', 'no issue');

        $this->client->request('GET', '/admin/configuration/places/new');
        $this->client->submitForm('Create', ['place[label]' => 'On hold', 'place[status]' => 'waiting']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#config-places-on_hold', 'Used by no workflow');

        $this->client->request('GET', '/admin/configuration/places/to_test');
        self::assertSelectorTextContains('turbo-frame#modal', 'An issue stands on this step');
        $this->client->submitForm('Save', ['place[label]' => 'Acceptance', 'place[status]' => 'closed']);
        $this->client->followRedirect();

        self::assertSame('Acceptance', $this->definition()->place('to_test')->label);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $tested = self::getContainer()->get(EntityManagerInterface::class)->find(Issue::class, $scene->issue('MD-SPT-4')->getId());
        self::assertInstanceOf(Issue::class, $tested);
        self::assertSame(PlaceStatus::Closed, $tested->getStatus());

        // A step workflows use stays; one nothing uses goes.
        $this->client->request('GET', '/admin/configuration/places/to_test');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'is used by: Bug, Feature, Internal task');

        $this->client->request('GET', '/admin/configuration/places/on_hold');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertFalse($this->definition()->hasPlace('on_hold'));
    }

    public function testFieldsAndLinkTypesAreManagedOnOnePage(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/fields');
        self::assertSelectorTextContains('#config-fields-environment', 'Single choice');
        self::assertSelectorTextContains('#config-fields-environment', 'Bug');
        self::assertSelectorTextContains('#config-fields-estimate', 'Internal users only');
        self::assertSelectorTextContains('#config-links-duplicates', 'Duplicated by');

        $this->client->request('GET', '/admin/configuration/fields/new');
        $this->client->submitForm('Create', ['field_settings[label]' => 'Browser', 'field_settings[type]' => 'select', 'field_settings[choices]' => "Chrome\nSafari"]);
        $this->client->followRedirect();
        self::assertSame(['chrome' => 'Chrome', 'safari' => 'Safari'], $this->definition()->field('browser_2')->options, 'the starter configuration already has a field `browser`');

        // A choice keeps its value when its label changes; a new line gets one.
        $this->client->request('GET', '/admin/configuration/fields/environment');
        self::assertSelectorNotExists('[name="field_settings[type]"]', 'the type is chosen once');
        $this->client->submitForm('Save', ['field_settings[choices]' => "prod = Live\npreprod = Pre-production\nLocal", 'field_settings[help]' => 'Where it happens.']);
        $this->client->followRedirect();
        self::assertSame(['prod' => 'Live', 'preprod' => 'Pre-production', 'local' => 'Local'], $this->definition()->field('environment')->options);
        self::assertSame('Where it happens.', $this->definition()->field('environment')->help);

        $this->client->request('GET', '/admin/configuration/fields/environment');
        $this->client->submitForm('Save', ['field_settings[choices]' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/admin/configuration/links/new');
        $this->client->submitForm('Create', ['link_type[label]' => 'Follows', 'link_type[inverseLabel]' => 'Followed by']);
        $this->client->followRedirect();
        self::assertSame('Followed by', $this->definition()->link('follows')->inverseLabel);

        $this->client->request('GET', '/admin/configuration/links/follows');
        $this->client->submitForm('Save', ['link_type[inverseLabel]' => '']);
        $this->client->followRedirect();
        self::assertTrue($this->definition()->link('follows')->isSymmetric());

        $this->client->request('GET', '/admin/configuration/links/follows');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertFalse($this->definition()->hasLink('follows'));
    }

    public function testAWorkflowShowsItsStepsActionsFieldsAndUsage(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/workflows');
        self::assertSelectorTextContains('#bug', 'Bug');
        self::assertSelectorTextContains('#bug', '1 category');
        self::assertSelectorExists('#bug a[href="/admin/configuration/workflows/bug"]');

        $crawler = $this->client->request('GET', '/admin/configuration/workflows/bug');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('to_test -.->|"Refuse 💬"| in_progress', $crawler->filter('[data-workflow-diagram-target="source"]')->text(), 'the graph, drawn by the browser');
        self::assertSelectorTextContains('main', 'A new issue starts on "New".');
        self::assertSelectorTextContains('#config-workflow_places-new', 'Initial');
        self::assertSelectorNotExists('#config-workflow_places-new a', 'the initial step does not leave');
        self::assertSelectorTextContains('#config-workflow_places-to_test', '1 issue');
        self::assertSelectorTextContains('#config-transitions-refuse', 'To test → In progress');
        self::assertSelectorTextContains('#config-transitions-refuse', 'Project manager, Client');
        self::assertSelectorTextContains('#config-transitions-refuse', 'comment required of external users');
        self::assertSelectorTextContains('#workflow-field-environment', 'Environment');
        self::assertSelectorTextContains('main', 'Online shop');

        $this->client->request('GET', '/admin/configuration/workflows/nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAWorkflowIsCreatedThenGivenItsStepsAndActions(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/workflows/new');
        $this->client->submitForm('Create', ['workflow[label]' => 'Support request', 'workflow[initial]' => 'new']);
        self::assertResponseRedirects('/admin/configuration/workflows/support_request');

        $this->client->request('GET', '/admin/configuration/workflows/support_request/places/add');
        self::assertSelectorNotExists('[name="workflow_place[place]"] option[value="new"]', 'the steps it has are not offered again');
        $this->client->submitForm('Add the step', ['workflow_place[place]' => 'done']);
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/configuration/workflows/support_request/actions/new');
        $this->client->submitForm('Create', ['transition[label]' => 'Answer', 'transition[to]' => 'done']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#modal', 'An action starts from at least one step.');

        $this->client->submitForm('Create', [
            'transition[label]' => 'Answer',
            'transition[from]' => ['new'],
            'transition[to]' => 'done',
            'transition[roles]' => ['project_manager', 'developer'],
            'transition[style]' => 'primary',
            'transition[requiresComment]' => true,
            'transition[commentPrompt]' => 'What was done?',
        ]);
        self::assertResponseRedirects('/admin/configuration/workflows/support_request');

        $answer = $this->definition()->workflow('support_request')->transition('answer');
        self::assertSame(['new'], $answer->from);
        self::assertSame('done', $answer->to);
        self::assertSame('What was done?', $answer->commentPrompt);

        $this->client->request('GET', '/admin/configuration/workflows/support_request/actions/answer');
        $this->client->submitForm('Save', ['transition[label]' => 'Reply', 'transition[requiresComment]' => false, 'transition[description]' => 'The request is answered.']);
        $this->client->followRedirect();
        $answer = $this->definition()->workflow('support_request')->transition('answer');
        self::assertSame('Reply', $answer->label);
        self::assertNull($answer->commentPrompt);
        self::assertSame('The request is answered.', $answer->description);

        $this->client->request('GET', '/admin/configuration/workflows/support_request/edit');
        $this->client->submitForm('Save', ['workflow[label]' => 'Support', 'workflow[initial]' => 'done']);
        $this->client->followRedirect();
        self::assertSame('done', $this->definition()->workflow('support_request')->initial);

        $this->client->request('GET', '/admin/configuration/workflows/support_request/actions/answer');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSame([], $this->definition()->workflow('support_request')->transitions);

        $this->client->request('GET', '/admin/configuration/workflows/support_request/edit');
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/admin/configuration/workflows');
        self::assertFalse($this->definition()->hasWorkflow('support_request'));

        // A workflow categories follow stays, and says so on its page.
        $this->client->request('GET', '/admin/configuration/workflows/bug/edit');
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/admin/configuration/workflows/bug');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A category follows the workflow "Bug"');
    }

    public function testAStepLeavesAWorkflowOnceItsIssuesWereSentElsewhere(): void
    {
        $scene = $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/workflows/bug/places/needs_info/remove');
        self::assertSelectorTextContains('turbo-frame#modal', 'Deleted · Ask for details');
        self::assertSelectorTextContains('turbo-frame#modal', 'Deleted · Answer');
        self::assertSelectorTextContains('turbo-frame#modal', 'No longer starts from there · Reject');
        self::assertSelectorTextNotContains('turbo-frame#modal', 'must go elsewhere');

        $this->client->request('GET', '/admin/configuration/workflows/bug/places/to_test/remove');
        self::assertSelectorTextContains('turbo-frame#modal', 'An issue stands on this step');
        $this->client->submitForm('Remove the step and move 1 issue', ['workflow_place[place]' => 'in_progress']);
        self::assertResponseRedirects('/admin/configuration/workflows/bug');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '"To test" left the workflow, and its issue moved.');

        $bug = $this->definition()->workflow('bug');
        self::assertFalse($bug->hasPlace('to_test'));
        self::assertArrayNotHasKey('deliver', $bug->transitions);
        self::assertArrayNotHasKey('accept', $bug->transitions);
        self::assertTrue($this->definition()->workflow('task')->hasPlace('to_test'), 'the other workflows keep the step');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $moved = self::getContainer()->get(EntityManagerInterface::class)->find(Issue::class, $scene->issue('MD-SPT-4')->getId());
        self::assertInstanceOf(Issue::class, $moved);
        self::assertSame('in_progress', $moved->getPlace());

        $this->client->request('GET', '/admin/configuration/workflows/bug/places/new/remove');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFieldsOfAWorkflowAreArrangedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $fields = $this->createLiveComponent('Config:WorkflowFields', ['workflow' => 'task'])->actingAs($scene->user('julie.admin@cairn.test'));
        self::assertCount(0, $this->definition()->workflow('task')->fields, 'the scene gives tasks no field');

        $fields->set('adding', 'url')->call('add');
        $fields->set('adding', 'goal')->call('add');
        $fields->call('toggle', ['field' => 'goal']);
        $fields->call('move', ['id' => 'goal', 'position' => 0]);

        $asked = $this->definition()->workflow('task')->fields;
        self::assertSame(['goal', 'url'], array_keys($asked));
        self::assertTrue($asked['goal']->required);
        self::assertFalse($asked['url']->required);

        $fields->call('remove', ['field' => 'url']);
        self::assertSame(['goal'], array_keys($this->definition()->workflow('task')->fields));
    }

    public function testEveryChangeIsWrittenInTheJournal(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/places/to_test');
        $this->client->submitForm('Save', ['place[status]' => 'closed']);
        $this->client->request('GET', '/admin/configuration/workflows/bug/places/needs_info/remove');
        $this->client->submitForm('Remove the step');
        // A refusal writes nothing.
        $this->client->request('GET', '/admin/configuration/roles/client');
        $this->client->submitForm('Delete');

        $crawler = $this->client->request('GET', '/admin/configuration/history');
        self::assertSelectorTextContains('aside', 'History');
        self::assertSame([
            'Julie Admin Workflow "Bug": step "Info requested" removed.',
            'Julie Admin Step "To test": status changed, and 1 issue followed it.',
            'Installation Configuration installed: 4 roles, 3 workflows, 12 steps, 7 fields, 3 link types.',
        ], $crawler->filter('main ol li')->each(static fn ($line): string => $line->filter('span')->eq(0)->text().' '.$line->filter('span')->eq(1)->text()));
    }

    public function testAnInstanceWithoutConfigurationIsOfferedItsStarterConfiguration(): void
    {
        $this->client->loginUser(UserFactory::new()->admin()->create(['email' => 'first.admin@cairn.test']));

        $this->client->request('GET', '/admin/configuration/roles');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'This instance has no configuration yet');

        $this->client->submitForm('Install the starter configuration');
        self::assertResponseRedirects('/admin/configuration/workflows');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Starter configuration installed.');
        self::assertSelectorTextContains('#bug', 'Bug');
        self::assertSame(['project_manager', 'developer', 'client', 'client_readonly'], $this->definition()->roleIds());
    }

    private function signInAsAdmin(): ShopScene
    {
        $scene = ShopScene::load(self::getContainer());
        $this->client->loginUser($scene->user('julie.admin@cairn.test'));

        return $scene;
    }

    private function definition(): InstanceDefinition
    {
        return self::getContainer()->get(ConfigRegistry::class)->definition();
    }
}

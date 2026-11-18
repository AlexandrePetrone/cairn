<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Markdown\MarkdownRenderer;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Project\CreateProject;
use App\Message\Command\Tracker\CreateTracker;
use App\Message\Command\Tracker\UpdateTracker;
use App\Message\Command\Workspace\CreateWorkspace;
use App\Message\CommandBus;

final class ProjectApiTest extends ApiTestCase
{
    public function testTheProjectsOfTheUserAreListed(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $projects = $this->api('GET', '/api/projects', $secret);
        self::assertResponseIsSuccessful();
        $keys = array_column($projects['member'], 'key');
        self::assertContains('MD-SPT', $keys);
        self::assertContains('MD-INT', $keys, 'An internal user opens every project.');

        $found = $this->api('GET', '/api/projects?q=intranet', $secret);
        self::assertSame(['MD-INT'], array_column($found['member'], 'key'));
    }

    public function testAProjectComesWithTheVolumeOfItsIssues(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $project = $this->api('GET', '/api/projects/MD-SPT', $secret);
        self::assertResponseIsSuccessful();
        self::assertSame('/api/projects/MD-SPT', $project['@id']);
        self::assertSame(['@type' => 'WorkspaceRef', 'key' => 'MD', 'name' => $this->scene->dupont->getName()], $project['workspace']);
        self::assertFalse($project['readOnly']);
        self::assertSame(8, $project['openIssues']);

        self::assertTrue($this->api('GET', '/api/projects/TR-RES', $secret)['readOnly']);

        $this->api('GET', '/api/projects/XX-YY', $secret);
        self::assertResponseStatusCodeSame(404);
    }

    public function testKeysMayHoldDigits(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $workspace = $commands->dispatch(new CreateWorkspace('Trois Vallées', '3V'));
        $project = $commands->dispatch(new CreateProject((int) $workspace->getId(), 'Ski pass 2000', 'SP2K'));
        $tracker = $commands->dispatch(new CreateTracker(null, (int) $project->getId(), 'Bugs', 'bug'));
        $admin = $this->scene->user('julie.admin@cairn.test');
        $commands->dispatch(new CreateIssue((int) $admin->getId(), (int) $project->getId(), (int) $tracker->getId(), 'The gates stay closed', fields: ['environment' => 'prod']));
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        self::assertSame('3V-SP2K', $this->api('GET', '/api/projects/3V-SP2K', $secret)['key']);
        self::assertResponseIsSuccessful();
        self::assertSame('The gates stay closed', $this->api('GET', '/api/issues/3V-SP2K-1', $secret)['title']);
        self::assertResponseIsSuccessful();

        $html = self::getContainer()->get(MarkdownRenderer::class)->render('See #3V-SP2K-1.', $this->scene->user(self::DEVELOPER), $project);
        self::assertStringContainsString('<a class="issue-reference" href="/3V/SP2K/issues/1"', $html);
    }

    public function testTheTrackersSayWhatAnIssueNeeds(): void
    {
        $trackers = $this->api('GET', '/api/projects/MD-SPT/trackers', $this->tokenFor(self::DEVELOPER)->secret);

        self::assertResponseIsSuccessful();
        self::assertSame([$this->scene->bugs->getId(), $this->scene->evolutions->getId(), $this->scene->tasks->getId()], array_column($trackers['member'], 'id'));
        $bug = $trackers['member'][0];
        self::assertSame(['@type' => 'WorkflowRef', 'id' => 'bug', 'label' => 'Bug'], $bug['workflow']);
        self::assertFalse($bug['archived']);
        self::assertSame(['@type' => 'TrackerPlace', 'id' => 'new', 'label' => 'New', 'initial' => true, 'closed' => false], $bug['places'][0]);
        $fields = array_column($bug['fields'], null, 'id');
        self::assertTrue($fields['environment']['required']);
        self::assertSame(['prod', 'preprod'], array_keys($fields['environment']['options']));
        self::assertTrue($fields['estimate']['internalOnly']);

        $this->api('GET', '/api/projects/XX-YY/trackers', $this->tokenFor(self::DEVELOPER)->secret);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnArchivedTrackerIsListedAndSaysSo(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateTracker((int) $this->scene->user(self::MANAGER)->getId(), (int) $this->scene->bugs->getId(), archived: true));

        $trackers = $this->api('GET', '/api/projects/MD-SPT/trackers', $secret)['member'];

        self::assertSame([$this->scene->bugs->getId(), $this->scene->evolutions->getId(), $this->scene->tasks->getId()], array_column($trackers, 'id'), 'In the order the project gives them.');
        self::assertSame([true, false, false], array_column($trackers, 'archived'));
        self::assertNotEmpty($trackers[0]['places'], 'Its issues are still moved along its steps.');
    }

    public function testTheMembersAreWhoAnIssueMayBeAssignedTo(): void
    {
        $members = $this->api('GET', '/api/projects/MD-SPT/members', $this->tokenFor(self::DEVELOPER)->secret);

        self::assertResponseIsSuccessful();
        $names = array_column($members['member'], 'name');
        self::assertContains('Nadia Benali', $names);
        self::assertContains('Claire Martin', $names);
        self::assertNotContains('Marc Petit', $names, 'A developer of another project is not a member.');
        $companies = array_column($members['member'], 'company', 'name');
        self::assertSame('Maison Dupont', $companies['Claire Martin']);
        self::assertArrayNotHasKey('Nadia Benali', $companies, 'no company: the property is left out');
    }

    public function testTheReleasesComeWithTheirProgress(): void
    {
        $release = $this->scene->release('2.5.0', ['MD-SPT-3', 'MD-SPT-5']);

        $releases = $this->api('GET', '/api/projects/MD-SPT/releases', $this->tokenFor(self::DEVELOPER)->secret);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $releases['member']);
        self::assertSame($release->getId(), $releases['member'][0]['id']);
        self::assertSame('2.5.0', $releases['member'][0]['name']);
        self::assertSame('open', $releases['member'][0]['status']);
        self::assertSame(2, $releases['member'][0]['issues']);
        self::assertSame(50, $releases['member'][0]['progress']);
    }
}

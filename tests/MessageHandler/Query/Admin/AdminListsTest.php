<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Admin;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\User\SearchUsers;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ListProjects, ListWorkspaces and SearchUsers, the lists of the
 * administration. The fixtures hold MD (SPT, INT) and GV (PRT).
 */
final class AdminListsTest extends KernelTestCase
{
    use KernelHelpers;

    private QueryBus $queries;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->queries = self::queryBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testProjectsAreSortedByWorkspaceArchivedLastAndFiltered(): void
    {
        $this->fixtures->intranet->archive();
        $this->fixtures->otherWorkspace->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(['MD-SPT', 'MD-INT', 'GV-PRT'], self::prefixes($this->queries->ask(new ListProjects())));
        self::assertSame(['MD-SPT'], self::prefixes($this->queries->ask(new ListProjects(state: [ListProjects::ACTIVE]))));
        self::assertSame(['MD-INT', 'GV-PRT'], self::prefixes($this->queries->ask(new ListProjects(state: [ListProjects::ARCHIVED]))), 'archived through its workspace too');
        self::assertSame(['MD-SPT', 'MD-INT'], self::prefixes($this->queries->ask(new ListProjects(q: 'dupont'))), 'by workspace name');
        self::assertSame(['MD-INT'], self::prefixes($this->queries->ask(new ListProjects(q: 'int'))));
        self::assertSame(['GV-PRT'], self::prefixes($this->queries->ask(new ListProjects(workspaceId: $this->fixtures->otherWorkspace->getId()))));
        self::assertSame([], $this->queries->ask(new ListProjects(q: '100%')), 'wildcards are text');
    }

    public function testWorkspacesAreFoundByTheirNameKeyOrProjects(): void
    {
        $names = static fn (array $workspaces): array => array_map(static fn (Workspace $workspace): string => $workspace->getKey(), $workspaces);

        self::assertSame(['MD', 'GV'], $names($this->queries->ask(new ListWorkspaces())));
        self::assertSame(['MD'], $names($this->queries->ask(new ListWorkspaces('intranet'))));
        self::assertSame(['GV'], $names($this->queries->ask(new ListWorkspaces('gv'))));

        $this->fixtures->otherWorkspace->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame(['MD'], $names($this->queries->ask(new ListWorkspaces(archived: false))));
        self::assertSame(['GV'], $names($this->queries->ask(new ListWorkspaces(archived: true))));
    }

    public function testUsersAreFilteredByTextTypeAndState(): void
    {
        $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->user('claire@client.test');
        $this->fixtures->user('paul@client.test')->deactivate();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $emails = static fn (SearchUsers $query, QueryBus $queries): array => array_map(static fn (User $user): string => $user->getEmail(), $queries->ask($query)->items);

        self::assertSame(['nadia@agency.test', 'claire@client.test', 'paul@client.test'], $emails(new SearchUsers(), $this->queries), 'active first, internal first');
        self::assertSame(['claire@client.test', 'paul@client.test'], $emails(new SearchUsers(type: [SearchUsers::EXTERNAL]), $this->queries));
        self::assertSame(['paul@client.test'], $emails(new SearchUsers(state: [SearchUsers::DEACTIVATED]), $this->queries));
        self::assertSame(['claire@client.test'], $emails(new SearchUsers(q: 'CLAIRE'), $this->queries));
        self::assertSame(3, $this->queries->ask(new SearchUsers())->total);
    }

    /**
     * @param list<Project> $projects
     *
     * @return list<string>
     */
    private static function prefixes(array $projects): array
    {
        return array_map(static fn (Project $project): string => $project->getIssuePrefix(), $projects);
    }
}

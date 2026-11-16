<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Project;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\CodeRepository;
use App\Entity\Comment;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\Forge;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\IssueSubscription;
use App\Entity\JournalEntry;
use App\Entity\ListView;
use App\Entity\Membership;
use App\Entity\PendingNotification;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Attachment\AddAttachment;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Forge\LinkRepository;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Project\CreateProject;
use App\Message\Command\Project\DeleteProject;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\User\RememberListView;
use App\Message\Command\Workspace\DeleteWorkspace;
use App\Message\Command\Workspace\UpdateWorkspace;
use App\Message\CommandBus;
use App\MessageHandler\Command\Project\ProjectException;
use App\MessageHandler\Command\Workspace\WorkspaceException;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * DeleteProject and DeleteWorkspace on the shop of the scene, which holds
 * a bit of everything: issues with threads, private tasks, access, and
 * here files, a release, a rule and its executions, a linked repository,
 * a link to another project, a view someone kept of a list.
 */
final class DeleteProjectTest extends KernelTestCase
{
    private ShopScene $scene;
    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private AttachmentStorage $storage;

    /** @var list<string> files the test left on disk */
    private array $paths = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->commands = self::getContainer()->get(CommandBus::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->storage = self::getContainer()->get(AttachmentStorage::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testAnArchivedProjectIsDeletedWithEverythingItHeldAndNothingElse(): void
    {
        $thomas = (int) $this->scene->user('thomas.girard@cairn.test')->getId();
        $shopId = (int) $this->scene->shop->getId();
        $intranetBug = $this->scene->intranetBug();
        $this->scene->release('2.5.0', ['MD-SPT-1']);
        $budget = $this->scene->budget();
        $this->scene->line($budget, BudgetLineStatus::Approved, 1, 'MD-SPT-1');
        $this->scene->line($budget, BudgetLineStatus::Approved, 0.5, label: 'Server upgrade');
        $budgetId = (int) $budget->getId();
        $this->commands->dispatch(new CreateRule($shopId, 'Greets', null, [
            'trigger' => ['type' => 'issue_created'],
            'actions' => [['type' => 'set_criticality', 'params' => ['criticality' => 'high']]],
        ], actorId: $thomas));
        $this->commands->dispatch(new LinkRepository($shopId, Forge::GitLab, 'dupont/shop', $thomas));
        $this->commands->dispatch(new AddLink($thomas, 'MD-SPT-1', 'related', $intranetBug->getKey()));
        $this->commands->dispatch(new AddComment($thomas, 'MD-SPT-2', 'See #'.$intranetBug->getKey().'.'));
        $this->commands->dispatch(new RememberListView($thomas, '/MD/SPT/issues', 'sort=updated'));
        $this->commands->dispatch(new RememberListView($thomas, '/MD/INT/issues', 'sort=updated'));
        $gone = $this->storage->path($this->attach('MD-SPT-1'));
        $kept = $this->storage->path($this->attach($intranetBug->getKey()));
        $users = $this->entityManager->getRepository(User::class)->count([]);
        self::assertGreaterThan(0, $this->rowsOf(JournalEntry::class, $shopId));

        try {
            $this->commands->dispatch(new DeleteProject($shopId));
            self::fail('A project is archived before it is deleted.');
        } catch (ProjectException $e) {
            self::assertStringContainsString('not archived', $e->getMessage());
        }
        $this->commands->dispatch(new UpdateProject($shopId, archived: true));

        $this->commands->dispatch(new DeleteProject($shopId));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Project::class)->find($shopId));
        foreach ([Issue::class, Tracker::class, Release::class, Budget::class, AutomationRule::class, AutomationRun::class, CodeRepository::class, Membership::class, Attachment::class] as $class) {
            self::assertSame(0, $this->entityManager->getRepository($class)->count(['project' => $shopId]), $class);
        }
        self::assertSame(0, $this->entityManager->getRepository(BudgetLine::class)->count(['budget' => $budgetId]), 'the lines of its budgets, whether they named an issue or not');
        foreach ([Comment::class, JournalEntry::class, IssueSubscription::class, PendingNotification::class] as $class) {
            self::assertSame(0, $this->rowsOf($class, $shopId), $class);
        }
        self::assertSame(0, $this->entityManager->getRepository(IssueLink::class)->count([]), 'a link to another project goes with its end');
        self::assertFileDoesNotExist($gone);
        self::assertSame(['/MD/INT/issues'], array_map(static fn (ListView $view): string => $view->getList(), $this->entityManager->getRepository(ListView::class)->findAll()));

        self::assertFileExists($kept);
        self::assertSame($users, $this->entityManager->getRepository(User::class)->count([]), 'accounts stay');
        self::assertNotNull($this->entityManager->getRepository(Issue::class)->findOneBy(['key' => $intranetBug->getKey()]));
        self::assertGreaterThan(0, $this->entityManager->getRepository(Membership::class)->count(['workspace' => $this->scene->dupont->getId()]), 'the access on the workspace stays');

        $again = $this->commands->dispatch(new CreateProject((int) $this->scene->dupont->getId(), 'New shop', 'SPT'));
        self::assertInstanceOf(Project::class, $again, 'the key is free again');
    }

    public function testAnArchivedWorkspaceIsDeletedWithItsProjectsAndItsAccess(): void
    {
        $workspaceId = (int) $this->scene->dupont->getId();
        $shopId = (int) $this->scene->shop->getId();
        $bookingId = (int) $this->scene->booking->getId();
        $gone = $this->storage->path($this->attach('MD-SPT-1'));

        try {
            $this->commands->dispatch(new DeleteWorkspace($workspaceId));
            self::fail('A workspace is archived before it is deleted.');
        } catch (WorkspaceException $e) {
            self::assertStringContainsString('not archived', $e->getMessage());
        }
        $this->commands->dispatch(new UpdateWorkspace($workspaceId, archived: true));

        $this->commands->dispatch(new DeleteWorkspace($workspaceId));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Workspace::class)->find($workspaceId));
        self::assertSame(0, $this->entityManager->getRepository(Project::class)->count(['workspace' => $workspaceId]));
        self::assertSame(0, $this->entityManager->getRepository(Membership::class)->count(['workspace' => $workspaceId]));
        self::assertSame(0, $this->entityManager->getRepository(Issue::class)->count(['project' => $shopId]));
        self::assertFileDoesNotExist($gone);
        self::assertNotNull($this->entityManager->getRepository(Project::class)->find($bookingId), 'the other workspace is left alone');
    }

    /**
     * @param class-string $class an entity that points to an issue
     */
    private function rowsOf(string $class, int $projectId): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(e.id) FROM %s e JOIN e.issue i WHERE i.project = :project', $class))
            ->setParameter('project', $projectId)
            ->getSingleScalarResult();
    }

    private function attach(string $issueKey): Attachment
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents($path, '%PDF-1.4');
        $attachment = $this->commands->dispatch(new AddAttachment((int) $this->scene->user('thomas.girard@cairn.test')->getId(), $issueKey, new UploadedFile($path, 'quote.pdf', null, null, true)));
        self::assertInstanceOf(Attachment::class, $attachment);
        $this->paths[] = $this->storage->path($attachment);

        return $attachment;
    }
}

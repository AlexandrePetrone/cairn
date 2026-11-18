<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Entity\Issue;
use App\Entity\User;
use App\Import\ImportMapping;
use App\Repository\IssueFieldValueRepository;
use App\Tests\Import\ArchiveBuilder;
use App\Tests\Import\ImportsArchives;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The imports in the administration, from the archive to the report,
 * with the example archive (see ArchiveBuilder) and the shop of the
 * scene. The worker's batches are run by the test between the pages.
 */
final class ImportControllerTest extends WebTestCase
{
    use ImportsArchives;

    private KernelBrowser $client;
    private ShopScene $scene;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->scene = ShopScene::load(self::getContainer());
        $this->directory = $this->importsDirectory();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testOnlyAdministratorsEnter(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        foreach (['/admin/imports', '/admin/imports/new', '/admin/imports/format'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheFormatPageShowsTheExampleToCopy(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/imports');
        self::assertSelectorTextContains('main', 'No import yet.');
        $this->client->clickLink('Format of the archive');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'issues.ndjson holds one issue per line');
        self::assertSelectorTextContains('[data-clipboard-target="source"]', '"email": "alice@agency.example"');
        self::assertCount(3, $this->client->getCrawler()->filter('[data-action="clipboard#copy"]'));
    }

    public function testAnArchiveIsSentOrChosenAndTheProjectIsRequired(): void
    {
        $this->signInAsAdmin();
        ArchiveBuilder::example($this->directory, 'waiting.zip');
        $this->client->request('GET', '/admin/imports/new');
        self::assertSelectorTextContains('select[name="import[archive]"]', 'waiting.zip');

        $this->client->submitForm('Analyse the archive', ['import[project]' => (string) $this->scene->shop->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Send a zip, or choose an archive waiting on the server.');

        $sent = ArchiveBuilder::example(sys_get_temp_dir().'/cairn-sent-'.bin2hex(random_bytes(4)), 'My export.zip');
        $this->client->submitForm('Analyse the archive', ['import[project]' => (string) $this->scene->shop->getId(), 'import[upload]' => new UploadedFile($sent, 'My export.zip', 'application/zip', null, true)]);
        $import = $this->import();
        self::assertResponseRedirects('/admin/imports/'.$import->getId());
        self::assertSame('My-export.zip', $import->getFile());
        self::assertFileExists($this->directory.'/My-export.zip');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Analysing the archive');
        self::assertSelectorExists('[data-poll]');
    }

    public function testAnImportIsConfiguredLaunchedAndReported(): void
    {
        $this->signInAsAdmin();
        // The reporters of the archive are called as the clients of Cairn are.
        ArchiveBuilder::example($this->directory, 'example.zip', static function (array $manifest, array $issues, array $files): array {
            $manifest['roles'][1]['label'] = 'Client';

            return [$manifest, $issues, $files];
        });
        $this->client->request('GET', '/admin/imports/new');
        $this->client->submitForm('Analyse the archive', ['import[archive]' => 'example.zip', 'import[project]' => (string) $this->scene->shop->getId()]);
        $id = $this->import()->getId();
        $this->work();

        $this->client->request('GET', '/admin/imports/'.$id);
        self::assertResponseRedirects('/admin/imports/'.$id.'/content');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The archive is complete and follows the format.');
        self::assertSelectorTextContains('main', 'Bug (1), Feature (1), Support (1)');

        $this->client->submitForm('Save and continue');
        self::assertResponseRedirects('/admin/imports/'.$id.'/mapping');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Bugs: statuses to the steps of "Bug"');
        self::assertSelectorExists('[data-live-name-value="Import:Mapping"]', 'the correspondences are a live component, see MappingTest');

        $this->client->request('GET', '/admin/imports/'.$id.'/accounts');
        self::assertSelectorTextContains('main', 'The 3 people of the archive');
        self::assertSelectorTextContains('main', '1 open issue assigned');
        self::assertSame('client', $this->client->getCrawler()->filter('select[name="import_accounts[people][102][role]"] option[selected]')->attr('value'), 'offered at first: the role that has the label of theirs in the archive');
        self::assertCount(0, $this->client->getCrawler()->filter('select[name="import_accounts[people][102][role]"] option[value="developer"]'), 'a new account is offered no internal role');
        $this->client->submitForm('Save and continue', ['import_accounts[people][102][active]' => '1', 'import_accounts[people][103][role]' => 'client_readonly']);
        self::assertResponseRedirects('/admin/imports/'.$id.'/summary');
        $people = ImportMapping::fromArray($this->import()->getMapping())->people;
        self::assertSame(['active' => true, 'role' => 'client'], $people['102']);
        self::assertSame(['active' => false, 'role' => 'client_readonly'], $people['103']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The import sends no mail and notifies nobody.');
        self::assertSelectorTextContains('main', '1 open issue will arrive assigned to nobody');
        self::assertSelectorTextContains('main', 'no role on the project: Alice Martin (1).', 'Carol, deactivated, has a role: she keeps hers');
        $this->client->submitForm('Launch the import');
        self::assertResponseRedirects('/admin/imports/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Importing');

        $this->work();
        $this->client->request('GET', '/admin/imports/'.$id);
        self::assertSame(ImportStatus::Completed, $this->import()->getStatus(), (string) $this->import()->getError());
        self::assertSelectorTextContains('main', 'Report');
        self::assertSelectorTextContains('main', '1 active account waits for its invitation');
        self::assertSelectorTextContains('main', 'Bob Client');
        self::assertSelectorTextContains('main', 'It cannot be imported again.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $carol = $entityManager->getRepository(User::class)->findOneBy(['email' => 'carol@client.example']);
        self::assertFalse($carol?->isActive());

        $issue = $entityManager->getRepository(Issue::class)->findOneBy(['importedRef' => '1001']);
        $this->client->request('GET', '/MD/SPT/issues/'.$issue?->getNumber());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'formerly 1001');
        self::assertSelectorTextContains('main', 'imported the issue from Redmine (1001)');

        $this->client->request('GET', '/admin/imports/new');
        self::assertSelectorTextNotContains('form', 'example.zip');
    }

    public function testAnArchiveWithNumericIdsGoesThroughEveryPage(): void
    {
        $this->signInAsAdmin();
        ArchiveBuilder::example($this->directory, 'redmine.zip', ArchiveBuilder::numericIds());
        $this->client->request('GET', '/admin/imports/new');
        $this->client->submitForm('Analyse the archive', ['import[archive]' => 'redmine.zip', 'import[project]' => (string) $this->scene->shop->getId()]);
        $id = $this->import()->getId();
        $this->work();
        self::assertSame(ImportStatus::Configuring, $this->import()->getStatus(), (string) $this->import()->getError());

        $this->client->request('GET', '/admin/imports/'.$id.'/mapping');
        self::assertResponseIsSuccessful();
        foreach (['content', 'accounts'] as $step) {
            $this->client->request('GET', '/admin/imports/'.$id.'/'.$step);
            self::assertResponseIsSuccessful($step);
            $this->client->submitForm('Save and continue');
            self::assertResponseRedirects(null, null, $step);
        }
        $mapping = ImportMapping::fromArray($this->import()->getMapping());
        self::assertSame(['1' => 'new', '2' => 'in_progress'], $mapping->places['bug']['1']);
        self::assertSame('field:environment', $mapping->fields['10']);

        $this->client->request('GET', '/admin/imports/'.$id.'/summary');
        $this->client->submitForm('Launch the import');
        $this->work();
        self::assertSame(ImportStatus::Completed, $this->import()->getStatus(), (string) $this->import()->getError());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $issue = $entityManager->getRepository(Issue::class)->findOneBy(['importedRef' => '1001']);
        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame(['environment' => 'prod'], self::getContainer()->get(IssueFieldValueRepository::class)->valuesOf($issue), 'matched by its label; the others have no field of their name');
        self::assertStringContainsString('- **Browser**: Firefox', $issue->getDescription());
        self::assertSame('1.1', $issue->getRelease()?->getName());
        self::assertSame('done', $entityManager->getRepository(Issue::class)->findOneBy(['importedRef' => '1002'])?->getPlace());
        $this->client->request('GET', '/admin/imports/'.$id);
        self::assertSelectorTextNotContains('main', 'was not created', 'everything the archive holds was written');
    }

    public function testAnImportWaitingForItsConfigurationIsAbandoned(): void
    {
        $this->signInAsAdmin();
        ArchiveBuilder::example($this->directory, 'example.zip');
        $this->client->request('GET', '/admin/imports/new');
        $this->client->submitForm('Analyse the archive', ['import[archive]' => 'example.zip', 'import[project]' => (string) $this->scene->shop->getId()]);
        $id = $this->import()->getId();
        $this->work();

        $this->client->request('GET', '/admin/imports/'.$id.'/summary');
        self::assertSelectorTextContains('main', '2 open issues will arrive assigned to nobody', 'nobody was given a role yet');
        self::assertSelectorTextContains('main', 'Alice Martin (1), Carol Former (1)');
        $this->client->submitForm('Abandon the import');
        self::assertResponseRedirects('/admin/imports');
        self::assertSame(ImportStatus::Abandoned, $this->import()->getStatus());

        $this->client->request('GET', '/admin/imports/'.$id.'/mapping');
        self::assertResponseRedirects('/admin/imports/'.$id);
        $this->client->request('GET', '/admin/imports/new');
        self::assertSelectorTextContains('select[name="import[archive]"]', 'example.zip');
    }

    private function signInAsAdmin(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
    }

    private function import(): Import
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Import::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException('No import.');
    }
}

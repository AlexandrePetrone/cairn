<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Attachment;
use App\Entity\User;
use App\Message\Command\Attachment\AddAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\CreateIssue;
use App\Message\CommandBus;
use App\Repository\AttachmentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TrackerRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Images pasted in an editor are sent pending, then follow the text that
 * points to them; any file is served to whoever may see it, images in the
 * page, the rest as a download. On the development dataset MD-SPT-4 is a
 * public shop issue; Paul only reads the project.
 */
final class AttachmentTest extends WebTestCase
{
    use Factories;

    /** A 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        AppStory::load();
    }

    public function testAPastedImageIsServedToItsUploaderUntilItsTextIsSaved(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $url = $this->upload();

        self::assertMatchesRegularExpression('#^/attachments/[0-9a-f-]{36}/capture\.png$#', $url);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename=capture.png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHasHeader('Last-Modified');
        $this->client->request('GET', str_replace('capture.png', 'other.png', $url));
        self::assertResponseStatusCodeSame(404, 'the name is part of the address');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'nobody else sees a pending file');

        $this->commands()->dispatch(new AddComment((int) $this->user('claire.martin@maison-dupont.test')->getId(), 'MD-SPT-4', \sprintf('Comme ceci : ![capture](%s)', $url)));
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('Thomas sees the comment, hence its image');
    }

    public function testTheImageOfAPrivateCommentIsNotFoundForAClient(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $url = $this->upload();
        $this->commands()->dispatch(new AddComment((int) $this->user('nadia.benali@cairn.test')->getId(), 'MD-SPT-4', \sprintf('![trace](%s)', $url), private: true));

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAFileThatIsNotAnImageIsOnlyDownloaded(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents($path, '<html><script>alert(1)</script></html>');
        $claire = $this->user('claire.martin@maison-dupont.test');
        $attachment = $this->commands()->dispatch(new AddAttachment((int) $claire->getId(), 'MD-SPT-4', new UploadedFile($path, 'page.html', 'text/html', null, true)));
        self::assertInstanceOf(Attachment::class, $attachment);

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', \sprintf('/attachments/%s/page.html', $attachment->getUuid()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=page.html');
    }

    public function testSendingFollowsTheRightToWrite(): void
    {
        $this->signInAs('paul.roussel@maison-dupont.test');
        $this->upload(expectedStatus: 403);
    }

    public function testOnlyTheEditorSends(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('POST', '/MD/SPT/attachments', files: ['file' => $this->file()]);
        self::assertResponseStatusCodeSame(400, 'without the header of the editor');
        self::assertResponseFormatSame('json');
    }

    public function testAnImageOfANewIssueIsClaimedByTheIssue(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $url = $this->upload();

        $project = self::getContainer()->get(ProjectRepository::class)->findOneByKeys('MD', 'SPT') ?? self::fail('no MD-SPT');
        $tracker = self::getContainer()->get(TrackerRepository::class)->findOneBy(['project' => $project, 'workflowId' => 'evolution']) ?? self::fail('no evolution tracker');
        $issue = $this->commands()->dispatch(new CreateIssue(
            (int) $this->user('claire.martin@maison-dupont.test')->getId(),
            (int) $project->getId(),
            (int) $tracker->getId(),
            'Bandeau de la page d\'accueil',
            \sprintf('Comme sur la maquette : ![capture](%s)', $url),
            fields: ['goal' => 'Mettre en avant les soldes.'],
        ));

        $attachment = self::getContainer()->get(AttachmentRepository::class)->findOneByUuid(Uuid::fromString(explode('/', $url)[2]));
        self::assertInstanceOf(Attachment::class, $attachment);
        self::assertSame($issue, $attachment->getIssue());
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('Thomas sees the issue, hence its image');
    }

    private function upload(int $expectedStatus = 201): string
    {
        $this->client->request('POST', '/MD/SPT/attachments', files: ['file' => $this->file()], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseFormatSame('json', 'the editor reads every answer as JSON');

        return 201 === $expectedStatus ? (string) json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['url'] : '';
    }

    private function file(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents((string) $path, base64_decode(self::PNG, true));

        return new UploadedFile((string) $path, 'capture.png', 'image/png', null, true);
    }

    private function commands(): CommandBus
    {
        return self::getContainer()->get(CommandBus::class);
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->user($email));
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]) ?? self::fail('no '.$email);
    }
}

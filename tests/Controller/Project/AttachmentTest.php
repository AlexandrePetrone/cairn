<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Attachment;
use App\Entity\User;
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
 * Images pasted in an editor: sent to the project, served to whoever sees
 * their issue. On the development dataset MD-SPT-4 is a public shop issue,
 * MD-SPT-18 an internal task; Paul only reads the project.
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

    public function testAnImageSentWithAnIssueIsServedToWhoeverSeesTheIssue(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $url = $this->upload('MD-SPT-4');

        self::assertMatchesRegularExpression('#^/attachments/[0-9a-f-]{36}/capture\.png$#', $url);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHasHeader('Last-Modified');
        $this->client->request('GET', str_replace('capture.png', 'other.png', $url));
        self::assertResponseStatusCodeSame(404, 'the name is part of the address');

        $this->signInAs('paul.roussel@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testAnImageOfAPrivateIssueIsNotFoundForAClient(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $url = $this->upload('MD-SPT-18');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSendingFollowsTheRightToWrite(): void
    {
        $this->signInAs('paul.roussel@maison-dupont.test');
        $this->upload('MD-SPT-4', expectedStatus: 403);
        $this->upload(null, expectedStatus: 403);

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->upload('MD-SPT-18', expectedStatus: 404);
    }

    public function testOnlyImagesComeFromTheEditor(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('POST', '/MD/SPT/attachments', files: ['file' => $this->file()]);
        self::assertResponseStatusCodeSame(400, 'without the header of the editor');
        self::assertResponseFormatSame('json');

        $text = tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents((string) $text, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->client->request('POST', '/MD/SPT/attachments', files: ['file' => new UploadedFile((string) $text, 'image.svg', 'image/svg+xml', null, true)], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnImageOfANewIssueBelongsToItsUploaderUntilTheIssueClaimsIt(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $url = $this->upload(null);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'nobody else sees an image without issue');

        $project = self::getContainer()->get(ProjectRepository::class)->findOneByKeys('MD', 'SPT') ?? self::fail('no MD-SPT');
        $tracker = self::getContainer()->get(TrackerRepository::class)->findOneBy(['project' => $project, 'workflowId' => 'evolution']) ?? self::fail('no evolution tracker');
        $issue = self::getContainer()->get(CommandBus::class)->dispatch(new CreateIssue(
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
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('Thomas sees the issue, hence its image');
    }

    private function upload(?string $issueKey, int $expectedStatus = 201): string
    {
        $this->client->request(
            'POST',
            '/MD/SPT/attachments',
            null === $issueKey ? [] : ['issue' => $issueKey],
            ['file' => $this->file()],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
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

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->user($email));
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]) ?? self::fail('no '.$email);
    }
}

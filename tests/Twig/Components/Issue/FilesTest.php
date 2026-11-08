<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Repository\AttachmentRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The Files block of MD-SPT-4, which Claire created: she adds and removes
 * its files. Nadia may edit the issue but did not write it; Paul only
 * reads.
 */
final class FilesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->issue = $this->scene->issue('MD-SPT-4');
    }

    public function testTheAuthorAddsFilesToTheIssue(): void
    {
        $component = $this->files('claire.martin@maison-dupont.test');
        self::assertStringContainsString('Add', (string) $component->render());
        $before = \count(self::getContainer()->get(AttachmentRepository::class)->findByIssue($this->issue));

        $component->call('upload', [], ['files' => [self::file('export.log'), self::file('quote.pdf')]]);
        $render = $component->render();

        self::assertStringContainsString('export.log', (string) $render);
        self::assertStringContainsString('quote.pdf', (string) $render);
        self::assertCount($before + 2, self::getContainer()->get(AttachmentRepository::class)->findByIssue($this->issue));
        self::assertSame(JournalEntryType::AttachmentAdded, $this->lastEntry()?->getType());
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => '2 file(s) added.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testAFileIsRemovedFromTheBlock(): void
    {
        $component = $this->files('claire.martin@maison-dupont.test');
        $component->call('upload', [], ['files' => [self::file('export.log')]]);
        $files = self::getContainer()->get(AttachmentRepository::class)->findByIssue($this->issue);
        $file = end($files) ?: self::fail('no file');

        $component->call('remove', ['uuid' => $file->getUuid()->toRfc4122(), 'name' => 'export.log']);

        $render = $component->render();
        self::assertStringNotContainsString('data-live-name-param="export.log"', (string) $render);
        self::assertSame(['message' => 'export.log removed.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
        self::assertNotContains('export.log', array_map(static fn ($file) => $file->getFilename(), self::getContainer()->get(AttachmentRepository::class)->findByIssue($this->issue)));
        self::assertSame(JournalEntryType::AttachmentRemoved, $this->lastEntry()?->getType());
    }

    public function testOnlyTheAuthorGetsTheButtons(): void
    {
        $this->files('claire.martin@maison-dupont.test')->call('upload', [], ['files' => [self::file('export.log')]]);

        foreach (['nadia.benali@cairn.test', 'paul.roussel@maison-dupont.test'] as $email) {
            $render = (string) $this->files($email)->render();
            self::assertStringContainsString('export.log', $render, $email);
            self::assertStringNotContainsString('Add', $render, $email);
            self::assertStringNotContainsString('data-live-action-param="remove"', $render, $email);
        }
    }

    public static function file(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents($path, 'content of '.$name);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function files(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Files', ['issue' => $this->issue])->actingAs($this->scene->user($email));
    }

    private function lastEntry(): ?JournalEntry
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * MD-SPT-4 is a bug: environment (required), url, browser, reproducible,
 * and estimate for internal users only.
 */
final class FieldsTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        AppStory::load();
        $this->issue = self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-4') ?? self::fail('no MD-SPT-4');
    }

    public function testWhoMayEditSeesTheButtonAndTheirFields(): void
    {
        self::assertStringContainsString('data-live-action-param="edit"', (string) $this->fields('claire.martin@maison-dupont.test')->render());
        self::assertStringNotContainsString('data-live-action-param="edit"', (string) $this->fields('paul.roussel@maison-dupont.test')->render(), 'read-only client');

        $client = (string) $this->fields('claire.martin@maison-dupont.test')->call('edit')->render();
        self::assertStringContainsString('fields[environment]', $client);
        self::assertStringNotContainsString('fields[estimate]', $client, 'internal-only field');

        self::assertStringNotContainsString('data-live-action-param="edit"', (string) $this->fields('nadia.benali@cairn.test')->render(), 'a developer did not write the issue');
        self::assertStringContainsString('fields[estimate]', (string) $this->fields('thomas.girard@cairn.test')->call('edit')->render(), 'a manager edits the issues of others and sees internal fields');
    }

    public function testAClientSavesANewValue(): void
    {
        // The story draws the environment at random: pick another one.
        [$value, $label] = 'preprod' === ($this->issue->getFieldValues()['environment'] ?? null) ? ['staging', 'Recette'] : ['preprod', 'Préproduction'];
        $component = $this->fields('claire.martin@maison-dupont.test')->call('edit');

        $component->submitForm(['fields' => ['environment' => $value]], 'save');
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode());
        self::assertStringContainsString($label, (string) $render);
        self::assertStringNotContainsString('fields[environment]', (string) $render, 'back to reading');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertNotNull($component->getDispatchedBrowserEvent($render, 'toast:show'));
        $entry = self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->issue, 'type' => JournalEntryType::FieldChanged], ['id' => 'DESC']);
        self::assertSame($label, $entry?->getData()['to'] ?? null);
    }

    public function testARequiredFieldCannotBeEmptied(): void
    {
        $component = $this->fields('claire.martin@maison-dupont.test')->call('edit');

        $this->expectException(UnprocessableEntityHttpException::class);
        $component->submitForm(['fields' => ['environment' => '']], 'save');
    }

    private function fields(string $email): TestLiveComponent
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $this->createLiveComponent('Issue:Fields', ['issue' => $this->issue])->actingAs($user);
    }
}

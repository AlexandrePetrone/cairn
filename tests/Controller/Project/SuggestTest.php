<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Repository\UserRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What the editor offers after `#` and `@`. On the development dataset
 * MD-SPT-18 is an internal task.
 */
final class SuggestTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        AppStory::load();
    }

    public function testIssuesAreThoseTheUserMaySee(): void
    {
        self::assertNotContains('MD-SPT-18', $this->issueKeys('claire.martin@maison-dupont.test', '18'));
        self::assertContains('MD-SPT-18', $this->issueKeys('nadia.benali@cairn.test', '18'));
    }

    public function testANumberFindsTheIssuesItStarts(): void
    {
        $keys = $this->issueKeys('nadia.benali@cairn.test', '1');

        self::assertContains('MD-SPT-1', $keys);
        self::assertContains('MD-SPT-12', $keys);
        self::assertLessThanOrEqual(8, \count($keys));
    }

    public function testPeopleAreTheMembersMatchingTheName(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/suggest/users?q=nad');

        self::assertResponseIsSuccessful();
        $people = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Nadia Benali'], array_column($people, 'name'));
    }

    /**
     * @return list<string>
     */
    private function issueKeys(string $email, string $query): array
    {
        $this->signInAs($email);
        $this->client->request('GET', '/MD/SPT/suggest/issues?q='.$query);
        self::assertResponseIsSuccessful();

        return array_column(json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR), 'key');
    }

    private function signInAs(string $email): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }
}

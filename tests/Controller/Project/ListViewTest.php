<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\ListView;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A list remembers how each person last arranged it: its address alone
 * means "my view of this list". Nadia develops on the shop of the scene,
 * Thomas manages it, Claire is its client.
 */
final class ListViewTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAnArrangementToldToTheListIsWhereItsAddressAloneLeads(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('PUT', '/MD/SPT/issues', content: 'criticality%5B%5D=high&group=place&q=cart&page=3&unknown=1');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseRedirects('/MD/SPT/issues?criticality%5B%5D=high&group=place', null, 'the filters and the grouping, not the page nor the words searched');

        $this->client->request('GET', '/MD/SPT/issues/assigned');
        self::assertResponseIsSuccessful('another list has its own view');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseIsSuccessful('a view is personal');
    }

    public function testOpeningAnArrangedAddressNeitherRedirectsNorRemembers(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('PUT', '/MD/SPT/issues', content: 'group=place');

        $this->client->request('GET', '/MD/SPT/issues?criticality[]=low');
        self::assertResponseIsSuccessful('the address someone shared shows as it says');

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseRedirects('/MD/SPT/issues?group=place', null, 'and leaves one\'s own view as it was');
    }

    public function testTheFrameOfTheResultsIsNeverRedirected(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('PUT', '/MD/SPT/issues', content: 'group=place');

        $this->client->request('GET', '/MD/SPT/issues', server: ['HTTP_TURBO_FRAME' => 'issues']);

        self::assertResponseIsSuccessful('clearing every filter on the page shows the list as it comes');
        self::assertSelectorNotExists('th[scope="rowgroup"]');
    }

    public function testTheListAsItComesIsNotAViewToKeep(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('PUT', '/MD/SPT/issues', content: 'group=place');
        self::assertSame(1, $this->views());

        $this->client->request('PUT', '/MD/SPT/issues', content: 'q=cart&page=2');

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->views(), 'the row goes');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseIsSuccessful();
    }

    public function testAnArrangementTooLongToKeepLeavesThePreviousOne(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('PUT', '/MD/SPT/issues', content: 'group=place');

        $this->client->request('PUT', '/MD/SPT/issues', content: implode('&', array_map(static fn (int $id): string => 'assignees%5B%5D='.$id, range(1, 400))));

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseRedirects('/MD/SPT/issues?group=place');
    }

    public function testTheViewOfATrackerRemembersItsKanban(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $url = '/MD/SPT/issues/tracker/'.$this->scene->bugs->getId();

        $this->client->request('PUT', $url, content: 'view=board&assignees%5B%5D=0');
        $this->client->request('GET', $url);

        self::assertResponseRedirects($url.'?view=board&assignees%5B%5D=0');
    }

    public function testAListRemembersOnlyForThoseWhoMayOpenIt(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('PUT', '/MD/SPT/issues/tracker/'.$this->scene->tasks->getId(), content: 'group=place');
        self::assertResponseStatusCodeSame(404, 'a private tracker does not exist for externals');

        $this->signInAs('sophie.bernard@travelia.test');
        $this->client->request('PUT', '/MD/SPT/issues', content: 'group=place');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->views());
    }

    public function testTheListsAcrossProjectsRememberTheirViewToo(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('PUT', '/issues/assigned', content: 'group=project');
        $this->client->request('GET', '/issues/assigned');

        self::assertResponseRedirects('/issues/assigned?group=project');
    }

    private function views(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ListView::class)->count([]);
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

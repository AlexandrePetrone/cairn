<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Enum\TagColour;
use App\Entity\Tag;
use App\Repository\IssueTagRepository;
use App\Repository\TagRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The tags of the shop: Thomas manages Maison Dupont, Nadia develops on
 * the shop, Claire is its client. MD-SPT-6 is an internal task.
 */
final class TagTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageIsInTheConfigurationOfTheProjectForItsManagerOnly(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('a[href="/MD/SPT/settings/tags"]');
        $this->client->request('GET', '/MD/SPT/settings/tags');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No tags yet');

        $tag = $this->scene->tag();
        foreach (['nadia.benali@cairn.test', 'claire.martin@maison-dupont.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues');
            self::assertSelectorNotExists('a[href="/MD/SPT/settings/tags"]');
            foreach (['GET /MD/SPT/settings/tags', 'GET /MD/SPT/settings/tags/new', \sprintf('GET /MD/SPT/settings/tags/%d', $tag->getId()), \sprintf('POST /MD/SPT/settings/tags/%d/delete', $tag->getId())] as $request) {
                $this->client->request(...explode(' ', $request));
                self::assertResponseStatusCodeSame(403, $email.' '.$request);
            }
        }
    }

    public function testTheManagerCreatesATagInADialog(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/tags');
        self::assertSelectorNotExists('[role="dialog"]');
        $this->client->clickLink('New tag');
        self::assertRouteSame('app_project_settings_tag_new');
        self::assertSelectorExists('turbo-frame#modal form[action="/MD/SPT/settings/tags/new"]', 'loaded over the list, the form is still sent to the dialog');
        self::assertSelectorCount(\count(TagColour::cases()), 'form[name="tag"] input[type="radio"][name="tag[colour]"]');

        $this->client->submitForm('Create the tag', ['tag[name]' => ' '], 'POST');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="dialog"]', 'the dialog stays open on the refusal');

        $this->client->submitForm('Create the tag', ['tag[name]' => 'Back office', 'tag[colour]' => 'teal']);
        self::assertResponseRedirects('/MD/SPT/settings/tags');
        self::assertSame(TagColour::Teal, $this->tag('Back office')->getColour());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Tag Back office created.');
        $this->client->clickLink('New tag');
        $this->client->submitForm('Create the tag', ['tag[name]' => 'back office', 'tag[colour]' => 'red']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="tag"]', 'already has a tag named back office');
    }

    public function testTheManagerRenamesAndPaintsATag(): void
    {
        $tag = $this->scene->tag('Back office', TagColour::Blue, ['MD-SPT-1']);
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/SPT/settings/tags/%d', $tag->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="tag[colour]"][value="blue"][checked]');
        $this->client->submitForm('Save', ['tag[name]' => 'Admin', 'tag[colour]' => 'rose']);
        self::assertResponseRedirects('/MD/SPT/settings/tags');
        self::assertSame(TagColour::Rose, $this->tag('Admin')->getColour());

        $this->client->request('GET', '/MD/SPT/issues/1');
        self::assertSelectorTextContains('main', 'Admin', 'the issue follows its tag');
    }

    public function testTheManagerDeletesATagThatIssuesCarry(): void
    {
        $tag = $this->scene->tag('Back office', TagColour::Blue, ['MD-SPT-1', 'MD-SPT-2']);
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/settings/tags');
        self::assertSelectorTextContains('main', '2 issues');
        $this->client->request('GET', \sprintf('/MD/SPT/settings/tags/%d', $tag->getId()));
        self::assertSelectorTextContains('[role="dialog"]', '2 issues carry this tag and will lose it');

        $this->client->submitForm('Delete the tag');
        self::assertResponseRedirects('/MD/SPT/settings/tags');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Tag Back office deleted.');
        self::assertNull(self::getContainer()->get(TagRepository::class)->findOneByName($this->scene->shop, 'Back office'));
        self::assertSame([], self::getContainer()->get(IssueTagRepository::class)->tagsOf($this->scene->issue('MD-SPT-1')));

        $this->client->request('GET', '/MD/SPT/issues/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'added the tag Back office', 'the history keeps the name of the tag');
    }

    public function testATagOfAnotherProjectIsNotFound(): void
    {
        $tag = $this->scene->tag();
        $this->scene->intranetBug();
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/INT/settings/tags/%d', $tag->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheDialogOfATagIsAnsweredAloneToItsFrame(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $frame = ['HTTP_TURBO_FRAME' => 'modal'];

        $this->client->request('GET', '/MD/SPT/settings/tags/new', [], [], $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal form[name="tag"]');
        self::assertSelectorNotExists('nav', 'nothing of the page behind the dialog');

        $this->client->submitForm('Create the tag', ['tag[name]' => 'Front', 'tag[colour]' => 'lime'], 'POST', $frame);
        self::assertResponseRedirects('/MD/SPT/settings/tags');
        $this->client->request('GET', '/MD/SPT/settings/tags', [], [], $frame);
        self::assertSelectorNotExists('turbo-frame#modal *', 'the frame is answered empty, which closes the dialog');
    }

    public function testTheListsOfTheProjectFilterByTag(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('input[name="tags[]"]', 'a project without tags offers no filter');

        $back = $this->scene->tag('Back office', TagColour::Teal, ['MD-SPT-3', 'MD-SPT-6']);
        $front = $this->scene->tag('Front', TagColour::Rose, ['MD-SPT-1', 'MD-SPT-3']);

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorCount(3, 'input[name="tags[]"]', 'both tags, and "No tag"');
        self::assertSelectorTextContains('main', 'Back office', 'the rows show their tags');

        $this->client->request('GET', \sprintf('/MD/SPT/issues?tags[]=%d', $back->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'MD-SPT-3');
        self::assertSelectorTextContains('main', '1 issue', 'the internal task stays hidden from the client');

        $this->client->request('GET', \sprintf('/MD/SPT/issues?tags[]=%d&tags[]=%d', $back->getId(), $front->getId()));
        self::assertSelectorTextContains('main', '2 issues');

        $this->client->request('GET', \sprintf('/MD/SPT/issues?tags[]=%d&tags[]=999999', $front->getId()));
        self::assertSelectorTextContains('main', '2 issues', 'a tag the project does not have filters nothing');

        $this->client->request('GET', '/MD/SPT/issues?tags[]=0');
        self::assertSelectorTextContains('main', 'MD-SPT-2');
        self::assertSelectorTextNotContains('main', 'MD-SPT-3');
        self::assertSelectorTextNotContains('main', 'MD-SPT-1 ');

        $this->client->request('GET', '/issues/created');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="tags[]"]', 'tags belong to a project: no filter across projects');
        self::assertSelectorTextContains('main', 'Front', 'the rows still show them');
    }

    private function tag(string $name): Tag
    {
        $tag = self::getContainer()->get(TagRepository::class)->findOneByName($this->scene->shop, $name);
        self::assertInstanceOf(Tag::class, $tag);

        return $tag;
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}

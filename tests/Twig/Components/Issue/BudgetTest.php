<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\BudgetLineStatus;
use App\Message\Command\Budget\UpdateBudgetLine;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The budget card of the page of an issue, on the shop of the scene:
 * Thomas manages its budgets, Claire is the client and reads them.
 */
final class BudgetTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use KernelHelpers;

    public function testTheCardFollowsTheLinesWhenTheIssueChanges(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $line = $scene->line($scene->budget(), BudgetLineStatus::Pending, 2, 'MD-SPT-8');
        $component = $this->createLiveComponent('Issue:Budget', ['issue' => $scene->issue('MD-SPT-8')])
            ->actingAs($scene->user('claire.martin@maison-dupont.test'));
        self::assertStringContainsString('To approve', (string) $component->render());

        // what a rule does when an action of the workflow is applied on the page
        self::commandBus()->dispatch(new UpdateBudgetLine((int) $scene->user('thomas.girard@cairn.test')->getId(), (int) $line->getId(), BudgetLineStatus::Approved));
        $render = (string) $component->emit('issue:changed')->render();

        self::assertStringContainsString('Approved', $render);
        self::assertStringNotContainsString('To approve', $render);
    }

    public function testTheCardIsHiddenWhenNoLineNamesTheIssue(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $scene->budget();

        $render = $this->createLiveComponent('Issue:Budget', ['issue' => $scene->issue('MD-SPT-1')])
            ->actingAs($scene->user('thomas.girard@cairn.test'))->render();

        self::assertStringContainsString('hidden', (string) $render->crawler()->filter('div')->first()->attr('class'));
        self::assertSame('', trim($render->crawler()->filter('div')->first()->text()));
    }
}

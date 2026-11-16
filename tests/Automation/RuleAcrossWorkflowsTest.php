<?php

declare(strict_types=1);

namespace App\Tests\Automation;

use App\Entity\Enum\Criticality;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Issue\AddComment;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A place is the same in every workflow that uses it: a rule that names one,
 * without a tracker, holds on the issues of each. On the shop of the scene,
 * whose bugs and tasks both have "In progress".
 */
final class RuleAcrossWorkflowsTest extends KernelTestCase
{
    public function testAStepConditionHoldsOnEveryTrackerThatHasThePlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $commands = self::getContainer()->get(CommandBus::class);
        $bug = $scene->issue('MD-SPT-3');
        $task = $scene->issue('MD-SPT-6');
        $feature = $scene->issue('MD-SPT-8');
        self::assertSame(['bug', 'task', 'evolution'], [$bug->getTracker()->getWorkflowId(), $task->getTracker()->getWorkflowId(), $feature->getTracker()->getWorkflowId()]);
        self::assertSame(['in_progress', 'in_progress', 'to_estimate'], [$bug->getPlace(), $task->getPlace(), $feature->getPlace()]);

        $commands->dispatch(new CreateRule((int) $bug->getProject()->getId(), 'Lower what is in progress', null, [
            'trigger' => ['type' => 'comment_added', 'params' => []],
            'conditions' => [['type' => 'place', 'params' => ['is' => ['in_progress']]]],
            'actions' => [['type' => 'set_criticality', 'params' => ['criticality' => 'low']]],
        ]));

        $thomas = (int) $scene->user('thomas.girard@cairn.test')->getId();
        foreach ([$bug, $task, $feature] as $issue) {
            $commands->dispatch(new AddComment($thomas, $issue->getKey(), 'Any news?'));
        }

        self::assertSame(Criticality::Low, $bug->getCriticality());
        self::assertSame(Criticality::Low, $task->getCriticality(), 'the same place in another workflow');
        self::assertSame(Criticality::High, $feature->getCriticality(), 'an issue on another place is left alone');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\Definition\InvalidDefinitionException;
use App\Tests\KernelHelpers;
use App\Tests\Workflow\Fixture\Subject;
use App\Workflow\WorkflowFactory;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class WorkflowProviderTest extends KernelTestCase
{
    use KernelHelpers;

    private WorkflowProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Nothing consumes the provider yet, so the container inlines it:
        // build it from its real dependencies.
        $this->provider = new WorkflowProvider(
            $container->get(ConfigRegistry::class),
            new WorkflowFactory($container->get(EventDispatcherInterface::class)),
        );
    }

    public function testWithoutSnapshotThereIsNoWorkflow(): void
    {
        $this->expectException(ConfigException::class);
        $this->provider->get('bug');
    }

    public function testWorkflowsComeFromTheSnapshotInForce(): void
    {
        self::applyConfiguration();

        $workflow = $this->provider->get('bug');

        self::assertSame('bug', $workflow->getName());
        self::assertSame($workflow, $this->provider->get('bug'), 'built once per process');
        self::assertSame('Bug', $this->provider->definition('bug')->label);

        $subject = new Subject();
        $workflow->apply($subject, 'start');
        self::assertSame('in_progress', $subject->getPlace());
    }

    public function testAnUnknownWorkflowIsAnError(): void
    {
        self::applyConfiguration();

        $this->expectException(InvalidDefinitionException::class);
        $this->provider->get('ghost');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\SetReferent;
use App\Message\CommandBus;
use App\Repository\MembershipRepository;
use App\Security\AccessControl;
use App\Security\Referents;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The referent of a role on a project: nobody until someone is
 * designated, on the project or on its workspace. Test roles, broadest
 * first: project_manager and developer (internal only), then client.
 */
final class ReferentsTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private AccessFixtures $fixtures;
    private Referents $referents;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new AccessFixtures($this->entityManager);
        $this->referents = new Referents(self::getContainer()->get(MembershipRepository::class), self::getContainer()->get(AccessControl::class));
    }

    public function testNobodyIsAReferentUntilDesignated(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $membership = $this->fixtures->grantOnProject($nadia, $this->fixtures->shop, 'developer');

        self::assertNull($this->referents->of($this->fixtures->shop, 'developer'), 'the only developer is not the referent by default');

        $this->commands->dispatch(new SetReferent((int) $membership->getId(), true));

        self::assertSame($nadia, $this->referents->of($this->fixtures->shop, 'developer'));
        self::assertNull($this->referents->of($this->fixtures->intranet, 'developer'), 'a project membership says nothing of the other projects');
    }

    public function testDesignatingAnotherMemberWithdrawsTheFirst(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $lea = $this->fixtures->user('lea@agency.test', internal: true);
        $first = $this->fixtures->grantOnProject($nadia, $this->fixtures->shop, 'developer');
        $second = $this->fixtures->grantOnProject($lea, $this->fixtures->shop, 'developer');

        $this->commands->dispatch(new SetReferent((int) $first->getId(), true));
        $this->commands->dispatch(new SetReferent((int) $second->getId(), true));

        self::assertSame($lea, $this->referents->of($this->fixtures->shop, 'developer'));
        self::assertFalse($first->isReferent());

        $this->commands->dispatch(new SetReferent((int) $second->getId(), false));
        self::assertNull($this->referents->of($this->fixtures->shop, 'developer'));
    }

    public function testAWorkspaceReferentAppliesToItsProjectsUnlessOneNamesItsOwn(): void
    {
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $claire = $this->fixtures->user('claire@client.test');
        $paul = $this->fixtures->user('paul@client.test');
        $this->fixtures->grantOnWorkspace($thomas, $this->fixtures->workspace, 'project_manager');
        $inherited = $this->fixtures->grantOnWorkspace($claire, $this->fixtures->workspace, 'client');
        $direct = $this->fixtures->grantOnProject($paul, $this->fixtures->shop, 'client');

        $this->commands->dispatch(new SetReferent((int) $inherited->getId(), true));
        self::assertSame($claire, $this->referents->of($this->fixtures->shop, 'client'));
        self::assertSame($claire, $this->referents->of($this->fixtures->intranet, 'client'));
        self::assertNull($this->referents->of($this->fixtures->otherProject, 'client'));

        $this->commands->dispatch(new SetReferent((int) $direct->getId(), true));
        self::assertSame($paul, $this->referents->of($this->fixtures->shop, 'client'), 'the project wins over its workspace');
        self::assertSame($claire, $this->referents->of($this->fixtures->intranet, 'client'));
        self::assertSame(['client' => $paul], $this->referents->all($this->fixtures->shop));
    }

    public function testAProjectSaysWhichMarkAnswersAndWhichItReplaces(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $paul = $this->fixtures->user('paul@client.test');
        $inherited = $this->fixtures->grantOnWorkspace($claire, $this->fixtures->workspace, 'client');
        $direct = $this->fixtures->grantOnProject($paul, $this->fixtures->shop, 'client');
        $this->commands->dispatch(new SetReferent((int) $inherited->getId(), true));

        $client = $this->referents->resolve($this->fixtures->shop)['client'];
        self::assertTrue($client->isInherited());
        self::assertSame($inherited, $client->membership());
        self::assertNull($client->replaced());

        $this->commands->dispatch(new SetReferent((int) $direct->getId(), true));
        $client = $this->referents->resolve($this->fixtures->shop)['client'];
        self::assertFalse($client->isInherited());
        self::assertSame($direct, $client->membership());
        self::assertSame($inherited, $client->replaced());

        $paul->deactivate();
        $this->entityManager->flush();
        $client = $this->referents->resolve($this->fixtures->shop)['client'];
        self::assertSame($claire, $client->user(), 'the workspace answers again behind a project referent who cannot');
        self::assertSame($paul, $client->marked());
        self::assertFalse($client->ownHolds);
    }

    public function testAMarkDoesNotOutliveTheRoleNorTheAccount(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $lea = $this->fixtures->user('lea@agency.test', internal: true);
        $nadiaAccess = $this->fixtures->grantOnProject($nadia, $this->fixtures->shop, 'developer');
        $leaAccess = $this->fixtures->grantOnWorkspace($lea, $this->fixtures->workspace, 'developer');

        $this->commands->dispatch(new SetReferent((int) $nadiaAccess->getId(), true));
        $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), null, $this->fixtures->shop->getId(), 'project_manager'));
        self::assertFalse($nadiaAccess->isReferent(), 'another role takes the mark away');
        self::assertNull($this->referents->of($this->fixtures->shop, 'developer'));

        $this->commands->dispatch(new SetReferent((int) $leaAccess->getId(), true));
        self::assertSame($lea, $this->referents->of($this->fixtures->shop, 'developer'));

        $lea->deactivate();
        $this->entityManager->flush();
        self::assertNull($this->referents->of($this->fixtures->shop, 'developer'), 'a deactivated account answers for nothing');
    }

    public function testAManagerDesignatesOnTheirProjectOnly(): void
    {
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $managerAccess = $this->fixtures->grantOnWorkspace($thomas, $this->fixtures->workspace, 'project_manager');
        $direct = $this->fixtures->grantOnProject($nadia, $this->fixtures->shop, 'developer');

        $this->commands->dispatch(new SetReferent((int) $direct->getId(), true, (int) $thomas->getId()));
        self::assertTrue($direct->isReferent());

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new SetReferent((int) $managerAccess->getId(), true, (int) $thomas->getId()));
    }
}

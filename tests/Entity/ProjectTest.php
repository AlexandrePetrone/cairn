<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Project;
use App\Entity\Workspace;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    public function testTheIssuePrefixCombinesWorkspaceAndProjectKeys(): void
    {
        $project = new Project(new Workspace('Maison Dupont', 'MD'), 'Online shop', 'SPT');

        self::assertSame('MD-SPT', $project->getIssuePrefix());
        self::assertFalse($project->isArchived());
    }

    public function testKeysMayHoldDigits(): void
    {
        $project = new Project(new Workspace('Trois Vallées', '3V'), 'Ski pass 2000', 'SP2K');

        self::assertSame('3V-SP2K', $project->getIssuePrefix());
    }

    public function testAKeyHoldsALetter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"12" is not a valid workspace key');

        new Workspace('Trois Vallées', '12');
    }

    public function testKeysAreUppercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"spt" is not a valid project key');

        new Project(new Workspace('Maison Dupont', 'MD'), 'Online shop', 'spt');
    }

    public function testAKeyHasTwoCharactersAtLeast(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Workspace('Maison Dupont', 'M');
    }

    public function testBlankDescriptionsAndNotesBecomeNull(): void
    {
        $workspace = new Workspace('Maison Dupont', 'MD');
        $project = new Project($workspace, 'Online shop', 'SPT');

        $project->setDescription('  ');
        $workspace->setNotes('');

        self::assertNull($project->getDescription());
        self::assertNull($workspace->getNotes());
    }
}

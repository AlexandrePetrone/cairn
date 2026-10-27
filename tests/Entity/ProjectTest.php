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
        $project = new Project(new Workspace('Maison Dupont', 'MD'), 'Site e-commerce', 'SPT');

        self::assertSame('MD-SPT', $project->getIssuePrefix());
        self::assertFalse($project->isArchived());
    }

    public function testKeysAreUppercaseLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"spt" is not a valid project key');

        new Project(new Workspace('Maison Dupont', 'MD'), 'Site e-commerce', 'spt');
    }

    public function testWorkspaceKeysAreUppercaseLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Workspace('Maison Dupont', 'M');
    }

    public function testBlankDescriptionsAndNotesBecomeNull(): void
    {
        $workspace = new Workspace('Maison Dupont', 'MD');
        $project = new Project($workspace, 'Site e-commerce', 'SPT');

        $project->setDescription('  ');
        $workspace->setNotes('');

        self::assertNull($project->getDescription());
        self::assertNull($workspace->getNotes());
    }
}

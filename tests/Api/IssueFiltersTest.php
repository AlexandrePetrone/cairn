<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Api\Mcp\SearchIssuesTool;
use App\Api\Resource\IssueResource;
use App\Entity\Enum\Criticality;
use App\Message\Query\Issue\IssueSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * The columns a list is sorted by are written where each door describes
 * them: the three lists must say the same.
 */
final class IssueFiltersTest extends TestCase
{
    public function testTheApiAndTheMcpServerOfferTheSortsOfTheLists(): void
    {
        $offered = array_values(array_filter(array_map(static fn (IssueSort $sort): ?string => IssueSort::Rank === $sort ? null : $sort->value, IssueSort::cases())));
        sort($offered);

        $documented = self::parameter('sort')['enum'];
        sort($documented);
        self::assertSame($offered, $documented, 'The `sort` parameter of GET /api/issues.');

        $choice = new \ReflectionProperty(SearchIssuesTool::class, 'sort')->getAttributes(Choice::class)[0]->newInstance();
        $choices = $choice->choices;
        self::assertIsArray($choices);
        sort($choices);
        self::assertSame($offered, $choices, 'The `sort` argument of search_issues.');
    }

    public function testTheApiDocumentsEveryCriticality(): void
    {
        self::assertSame(Criticality::values(), self::parameter('criticality[]')['items']['enum']);
    }

    /**
     * The schema of a filter of GET /api/issues.
     *
     * @return array<string, mixed>
     */
    private static function parameter(string $name): array
    {
        $resource = new \ReflectionClass(IssueResource::class)->getAttributes(ApiResource::class)[0]->newInstance();
        foreach ($resource->getOperations() ?? [] as $operation) {
            if ($operation instanceof GetCollection) {
                foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                    if ($key === $name) {
                        return $parameter->getSchema() ?? [];
                    }
                }
            }
        }

        self::fail(\sprintf('GET /api/issues has no %s parameter.', $name));
    }
}

<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;
use App\Entity\Enum\Criticality;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\Message\QueryBus;
use App\Security\Viewer;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GET /issues: the filters of the query string become a SearchIssues, so
 * the list is the one the application shows for the same filters. A
 * filter the API does not understand is a 400, never ignored: a program
 * must not take a wider list for the one it asked.
 *
 * @implements ProviderInterface<IssueResource>
 */
final class IssueCollectionProvider implements ProviderInterface
{
    private const string ME = 'me';
    private const string NONE = 'none';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly IssueMapper $issues,
        private readonly Pagination $pagination,
    ) {
    }

    /**
     * @return TraversablePaginator<IssueResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $filters = $request->query;
        $viewer = $this->viewer->get();

        $projectId = null;
        if ($filters->has('project')) {
            $project = $this->lookup->findProject($filters->getString('project')) ?? throw new BadRequestHttpException('project: no such project.');
            $projectId = (int) $project->getId();
        }
        $sort = $filters->has('sort') ? (IssueSort::tryFrom($filters->getString('sort')) ?? throw new BadRequestHttpException('sort: unknown column.')) : null;
        $order = match ($filters->getString('order', 'asc')) {
            'asc' => \SortDirection::Ascending,
            'desc' => \SortDirection::Descending,
            default => throw new BadRequestHttpException('order: asc or desc.'),
        };

        $page = $this->queries->ask(new SearchIssues(
            viewer: $viewer,
            projectId: $projectId,
            authorId: $filters->has('author') ? self::id($filters->getString('author'), 'author', [self::ME => (int) $viewer->getId()]) : null,
            place: self::list($filters, 'place'),
            tracker: array_map(static fn (string $value): int => self::id($value, 'tracker'), self::list($filters, 'tracker')),
            assignee: array_map(static fn (string $value): int => self::id($value, 'assignee', [self::ME => (int) $viewer->getId(), self::NONE => 0]), self::list($filters, 'assignee')),
            criticality: array_map(static fn (string $value): Criticality => Criticality::tryFrom($value) ?? throw new BadRequestHttpException('criticality: unknown level.'), self::list($filters, 'criticality')),
            release: array_map(static fn (string $value): int => self::id($value, 'release', [self::NONE => 0]), self::list($filters, 'release')),
            q: $filters->has('q') ? $filters->getString('q') : null,
            closed: $filters->has('closed') && ($filters->getBoolean('closed') || '' === $filters->getString('closed')),
            page: $this->pagination->getPage($context),
            sort: $sort,
            order: $order,
            perPage: $this->pagination->getLimit($operation, $context),
        ));

        return new TraversablePaginator(new \ArrayIterator(array_map($this->issues->summary(...), $page->items)), $page->page, $page->perPage, $page->total);
    }

    /**
     * The values of a filter given once (`tracker=3`) or several times (`tracker[]=3&tracker[]=4`).
     *
     * @param InputBag<string> $filters
     *
     * @return list<string>
     */
    private static function list(InputBag $filters, string $name): array
    {
        $values = $filters->all()[$name] ?? [];
        $values = \is_array($values) ? array_values($values) : [$values];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new BadRequestHttpException($name.': a value or a list of values.');
            }
        }

        return $values;
    }

    /**
     * @param array<string, int> $words the words the filter takes in place of an id
     */
    private static function id(string $value, string $name, array $words = []): int
    {
        if (isset($words[$value])) {
            return $words[$value];
        }
        if (!ctype_digit($value) || \strlen($value) > 9 || 0 === (int) $value) {
            throw new BadRequestHttpException($name.': an id'.([] === $words ? '' : ', '.implode(' or ', array_keys($words))).'.');
        }

        return (int) $value;
    }
}

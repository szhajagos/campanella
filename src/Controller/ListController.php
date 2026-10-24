<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Lists\ListDefinition;
use Campanella\Lists\ListRegistry;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Query\FieldFilters;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\Site\MetaBuilder;
use Campanella\View\Presentation;
use DateTimeZone;

/**
 * The page of a list made in the admin (since 0.2.5), at its path: its objects,
 * a page at a time (`?page=2`), as teasers or as a table. The template is the
 * list's own, `list/<name>.html.twig`, if a theme has one, else that of its
 * display, `list/teaser.html.twig` or `list/table.html.twig`.
 *
 * Its visitors' filters (since 0.2.9) are in `?f[…]` (FieldFilters), shown by
 * `list/_filters.html.twig`; the pages keep them; a filtered page is `noindex`, its
 * canonical address the list's own.
 */
final class ListController implements Controller
{
    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Presentation $presentation,
        private readonly ListRegistry $lists,
        private readonly BlueprintRegistry $blueprints,
        private readonly DateTimeZone $timezone,
        private readonly ?RelationLoader $relations = null,
        private readonly ?MetaBuilder $meta = null,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $list = $this->lists->find((string) ($route->params['list'] ?? '')) ?? throw HttpException::notFound();
        $page = max(1, $request->queryInt('page', 1));
        $query = $list->query($this->blueprints, $this->timezone);

        // The visitor's filters (since 0.2.9), added to the list's own conditions.
        $blueprint = $this->blueprints->get($list->blueprint);
        $offered = [];
        foreach ($list->filters as $name) {
            $filter = ListDefinition::filter($blueprint, $name);
            if ($filter !== null) {
                $offered[] = $filter;
            }
        }
        [$query, $values, $options] = (new FieldFilters($this->queries, $this->timezone))
            ->apply($query, $offered, is_array($request->query['f'] ?? null) ? $request->query['f'] : [], $actor);
        $filters = [];
        foreach ($offered as $filter) {
            $kind = $filter instanceof Field ? FieldFilters::kindOf($filter) : 'relation';
            if (in_array($kind, ['choice', 'relation'], true) && !isset($options[$filter->name])) {
                continue; // a relation with too many targets to offer
            }
            $filters[] = ['name' => $filter->name, 'label' => $filter->label !== '' ? $filter->label : $filter->name, 'kind' => $kind, 'options' => $options[$filter->name] ?? []];
        }
        $filtered = http_build_query(['f' => $values]);

        $result = $this->queries->execute($query->page($page, $list->perPage), $actor, withTotal: true);
        if ($page > 1 && $result->isEmpty()) {
            throw HttpException::notFound();
        }
        $this->relations?->resolve($result, $actor);

        $content = $this->presentation->renderFirst(
            ['list/' . $list->name . '.html.twig', 'list/' . $list->display . '.html.twig'],
            [
                'result' => $result,
                'list' => $list,
                'columns' => self::columns($list, $this->blueprints),
                'path' => $request->path,
                'filters' => $filters,
                'filter_values' => $values,
                'filter_query' => $filtered,
            ],
        );
        $meta = $this->meta?->forPath($request->path, $list->title, $page);
        if ($meta !== null && $values !== []) {
            // A filtered page is not indexed: its canonical address is the list's own.
            $meta = $meta->notIndexed($this->meta->forPath($request->path, $list->title)->canonical);
        }

        return Response::html($this->presentation->render('page/query.html.twig', [
            'title' => $list->title,
            'content' => $content,
            'meta' => $meta,
        ]));
    }

    /**
     * A table's columns: the title first (a link to the object), then the chosen fields.
     *
     * @return list<array{name: string, label: string}>
     */
    public static function columns(ListDefinition $list, BlueprintRegistry $blueprints): array
    {
        $fields = $blueprints->get($list->blueprint)->allFields();
        $columns = [];
        foreach ($list->columns as $name) {
            if (isset($fields[$name]) && $name !== 'title') {
                $columns[] = ['name' => $name, 'label' => $fields[$name]->label !== '' ? $fields[$name]->label : $name];
            }
        }

        return $columns;
    }
}

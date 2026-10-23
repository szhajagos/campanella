<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Database\Connection;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\Router;
use Campanella\I18n\Message;
use Campanella\Lists\ListDefinition;
use Campanella\Lists\ListPathCheck;
use Campanella\Lists\ListRegistry;
use Campanella\Lists\ListStore;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Security\Csrf;
use Closure;

/**
 * Lists made in the admin (since 0.2.5), under /admin/system/lists, for the system
 * roles: the overview, a new list (its name, title and Blueprint), its page (title,
 * path, per page, display, columns, sort order, conditions on fields and relations),
 * deleting it. Every save is checked by ListDefinition, and the path against the
 * routes, the admin, the public folders and the objects' paths.
 */
final class ListEditor
{
    /** The condition rows of the form: the list's own and this many empty ones. */
    public const int EMPTY_ROWS = 3;

    /** The most targets offered for a relation condition. */
    public const int MAX_TARGETS = 200;

    public function __construct(
        private readonly ListStore $store,
        private readonly ListRegistry $lists,
        private readonly BlueprintRegistry $blueprints,
        private readonly Router $router,
        private readonly AdminAccess $access,
        private readonly Connection $db,
        private readonly QueryEngine $queries,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/lists
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        return match (true) {
            $segments === [] => $render('lists', 'lists', [
                'title' => 'lists.title',
                'lists' => array_map(fn (ListDefinition $l): array => [
                    'name' => $l->name,
                    'title' => $l->title,
                    'blueprint' => $this->blueprints->get($l->blueprint)->label,
                    'path' => $l->path,
                    'display' => $l->display,
                ], array_values($this->lists->all())),
                'errors' => $this->lists->errors(),
            ]),
            $segments === ['new'] => $this->create($request, $render),
            count($segments) === 2 && $segments[1] === 'delete' => $this->delete($request, $segments[0]),
            count($segments) === 1 => $this->edit($request, $actor, $segments[0], $render),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function create(Request $request, Closure $render): Response
    {
        $form = ['name' => '', 'title' => '', 'blueprint' => ''];
        $errors = [];
        if ($request->isPost()) {
            $form = ['name' => trim($request->postString('name')), 'title' => trim($request->postString('title')), 'blueprint' => $request->postString('blueprint')];
            if (!$this->csrf->isValid($request)) {
                $errors['form'] = new Message('auth.form_expired');
            } else {
                try {
                    BlueprintDefinition::name($form['name']);
                    if ($this->store->find($form['name']) !== null) {
                        $errors['name'] = new Message('blueprints.name_taken');
                    }
                } catch (\InvalidArgumentException) {
                    $errors['name'] = new Message('blueprints.invalid_name');
                }
                if ($errors === []) {
                    try {
                        $list = ListDefinition::fromArray($form['name'], ['title' => $form['title'], 'blueprint' => $form['blueprint']], $this->blueprints);
                        $this->store->save($list->name, $list->toArray());
                        $this->flash->add(Flash::SUCCESS, new Message('lists.created', ['title' => $list->title]));

                        return $this->redirect($request, $list->name);
                    } catch (\InvalidArgumentException $e) {
                        $errors['form'] = new Message('lists.invalid', ['reason' => $e->getMessage()]);
                    }
                }
            }
        }
        $response = $render('list_new', 'lists', ['title' => 'lists.new', 'form' => $form, 'errors' => $errors, 'blueprints' => $this->listable()]);

        return $request->isPost() ? self::status($response, 422) : $response;
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function edit(Request $request, Actor $actor, string $name, Closure $render): Response
    {
        $stored = $this->store->find($name) ?? throw HttpException::notFound();
        $blueprint = $this->blueprints->find(is_string($stored['blueprint'] ?? null) ? $stored['blueprint'] : '') ?? throw HttpException::notFound();
        $form = self::form($stored);
        $errors = [];
        if ($request->isPost()) {
            $form = $this->posted($request, $blueprint);
            if (!$this->csrf->isValid($request)) {
                $errors['form'] = new Message('auth.form_expired');
            } else {
                $definition = [
                    'title' => $form['title'],
                    'blueprint' => $blueprint->name,
                    'conditions' => [...$form['conditions'], ...$form['relations']],
                    'sort' => $form['sort'],
                    'per_page' => ctype_digit($form['per_page']) ? (int) $form['per_page'] : 0,
                    'display' => $form['display'],
                    'columns' => $form['columns'],
                    'path' => $form['path'] === '' ? null : $form['path'],
                ];
                try {
                    $list = ListDefinition::fromArray($name, $definition, $this->blueprints);
                    if ($list->path !== null && $this->pathTaken($list->path, $name)) {
                        $errors['path'] = new Message('validation.path_reserved', ['path' => $list->path]);
                    } else {
                        $this->store->save($name, $list->toArray());
                        $this->flash->add(Flash::SUCCESS, new Message('lists.saved', ['title' => $list->title]));

                        return $this->redirect($request, $name);
                    }
                } catch (\InvalidArgumentException $e) {
                    $errors['form'] = new Message('lists.invalid', ['reason' => $e->getMessage()]);
                }
            }
        }

        $fields = [];
        $sortable = [];
        foreach (['created', 'updated', ...array_keys($blueprint->allFields())] as $fieldName) {
            $field = ListDefinition::queryable($blueprint, $fieldName);
            if ($field !== null) {
                $fields[] = ['name' => $field->name, 'label' => $field->label !== '' ? $field->label : $field->name, 'type' => $field->type->value, 'choices' => $field->choices];
                if (!$field->isMultiple()) {
                    $sortable[] = ['name' => $field->name, 'label' => $field->label !== '' ? $field->label : $field->name];
                }
            }
        }
        $columns = [];
        foreach ($blueprint->allFields() as $field) {
            if (!$field->hidden && $field->name !== 'title' && !in_array($field->name, ['body', 'format', 'status', 'tree_path', 'depth'], true)) {
                $columns[] = ['name' => $field->name, 'label' => $field->label !== '' ? $field->label : $field->name];
            }
        }
        $relations = [];
        foreach ($blueprint->allRelations() as $relation) {
            $query = Query::objects();
            if ($relation->targetBlueprints !== []) {
                $query = $query->blueprint(...$relation->targetBlueprints);
            }
            if ($relation->targetCapabilities !== []) {
                $query = $query->having(...$relation->targetCapabilities);
            }
            $targets = $this->queries->execute($query->orderBy('id')->limit(self::MAX_TARGETS), $actor)->items;
            $relations[] = [
                'name' => $relation->name,
                'label' => $relation->label !== '' ? $relation->label : $relation->name,
                'targets' => array_map(static fn (CampanellaObject $o): array => [
                    'id' => (int) $o->id(),
                    'title' => $o->hasField('title') && (string) $o->get('title') !== '' ? (string) $o->get('title') : '#' . $o->id(),
                ], $targets),
            ];
        }
        while (count($form['conditions']) < count(self::conditionRows($stored)) + self::EMPTY_ROWS && count($form['conditions']) < ListDefinition::MAX_CONDITIONS) {
            $form['conditions'][] = ['field' => '', 'op' => '=', 'value' => '', 'relative' => ''];
        }
        while (count($form['sort']) < ListDefinition::MAX_SORT) {
            $form['sort'][] = ['field' => '', 'dir' => 'asc'];
        }

        $response = $render('list_edit', 'lists', [
            'title' => 'lists.edit',
            'list' => ['name' => $name, 'blueprint' => $blueprint->label, 'live_path' => $this->lists->find($name)?->path],
            'form' => $form,
            'errors' => $errors,
            'fields' => $fields,
            'sortable' => $sortable,
            'columns' => $columns,
            'relations' => $relations,
            'operators' => ListDefinition::OPERATORS,
            'displays' => ListDefinition::DISPLAYS,
            'max_per_page' => ListDefinition::MAX_PER_PAGE,
        ]);

        return $request->isPost() ? self::status($response, 422) : $response;
    }

    /**
     * The form's values from a stored definition.
     *
     * @param array<string, mixed> $stored
     * @return array{title: string, path: string, per_page: string, display: string, columns: list<string>, sort: list<array{field: string, dir: string}>, conditions: list<array{field: string, op: string, value: string, relative: string}>, relations: list<array{relation: string, targets: list<int>}>}
     */
    private static function form(array $stored): array
    {
        $relations = [];
        foreach (is_array($stored['conditions'] ?? null) ? $stored['conditions'] : [] as $condition) {
            if (is_array($condition) && isset($condition['relation'])) {
                $relations[] = ['relation' => (string) $condition['relation'], 'targets' => array_values(array_map(intval(...), (array) ($condition['targets'] ?? [])))];
            }
        }

        return [
            'title' => (string) ($stored['title'] ?? ''),
            'path' => (string) ($stored['path'] ?? ''),
            'per_page' => (string) ($stored['per_page'] ?? 10),
            'display' => (string) ($stored['display'] ?? 'teaser'),
            'columns' => array_values(array_map(strval(...), is_array($stored['columns'] ?? null) ? $stored['columns'] : [])),
            'sort' => array_values(array_map(static fn (mixed $s): array => ['field' => (string) (is_array($s) ? ($s['field'] ?? '') : ''), 'dir' => is_array($s) && ($s['dir'] ?? '') === 'desc' ? 'desc' : 'asc'], is_array($stored['sort'] ?? null) ? $stored['sort'] : [])),
            'conditions' => self::conditionRows($stored),
            'relations' => $relations,
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @return list<array{field: string, op: string, value: string, relative: string}>
     */
    private static function conditionRows(array $stored): array
    {
        $rows = [];
        foreach (is_array($stored['conditions'] ?? null) ? $stored['conditions'] : [] as $condition) {
            if (is_array($condition) && isset($condition['field'])) {
                $value = $condition['value'] ?? '';
                $rows[] = [
                    'field' => (string) $condition['field'],
                    'op' => (string) ($condition['op'] ?? '='),
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : ''),
                    'relative' => (string) ($condition['relative'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * The posted form, in the stored form's terms (empty rows left out).
     *
     * @return array{title: string, path: string, per_page: string, display: string, columns: list<string>, sort: list<array{field: string, dir: string}>, conditions: list<array<string, mixed>>, relations: list<array{relation: string, targets: list<int>}>}
     */
    private function posted(Request $request, Blueprint $blueprint): array
    {
        $post = $request->post;
        $strings = static fn (mixed $value): array => array_values(array_filter(is_array($value) ? $value : [], is_string(...)));
        $conditions = [];
        foreach (is_array($post['conditions'] ?? null) ? $post['conditions'] : [] as $row) {
            if (!is_array($row) || !is_string($row['field'] ?? null) || $row['field'] === '') {
                continue;
            }
            $condition = ['field' => $row['field'], 'op' => is_string($row['op'] ?? null) ? $row['op'] : '='];
            if (is_string($row['relative'] ?? null) && $row['relative'] !== '') {
                $condition['relative'] = $row['relative'];
            } elseif (!in_array($condition['op'], ['empty', 'not_empty'], true)) {
                $condition['value'] = is_string($row['value'] ?? null) ? trim($row['value']) : '';
            }
            $conditions[] = $condition;
        }
        $relations = [];
        foreach ($blueprint->allRelations() as $relation) {
            $targets = array_values(array_unique(array_map(intval(...), array_filter($strings($post['relations'][$relation->name] ?? []), ctype_digit(...)))));
            if ($targets !== []) {
                $relations[] = ['relation' => $relation->name, 'targets' => $targets];
            }
        }
        $sort = [];
        foreach (is_array($post['sort'] ?? null) ? $post['sort'] : [] as $row) {
            if (is_array($row) && is_string($row['field'] ?? null) && $row['field'] !== '') {
                $sort[] = ['field' => $row['field'], 'dir' => ($row['dir'] ?? '') === 'desc' ? 'desc' : 'asc'];
            }
        }

        return [
            'title' => trim($request->postString('title')),
            'path' => trim($request->postString('path')),
            'per_page' => trim($request->postString('per_page')),
            'display' => $request->postString('display'),
            'columns' => $strings($post['columns'] ?? []),
            'sort' => $sort,
            'conditions' => $conditions,
            'relations' => $relations,
        ];
    }

    /** Whether another route, the admin, a public folder or an object has the path. */
    private function pathTaken(string $path, string $name): bool
    {
        return (new ListPathCheck($this->router, $this->access, $this->db))->isTaken($path, $name);
    }

    private function delete(Request $request, string $name): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $stored = $this->store->find($name) ?? throw HttpException::notFound();
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $this->redirect($request, $name);
        }
        $this->store->delete($name);
        $this->flash->add(Flash::SUCCESS, new Message('lists.deleted', ['title' => (string) ($stored['title'] ?? $name)]));

        return Response::redirect($request->basePath . $this->access->path('system/lists'), 303);
    }

    /** @return list<array{name: string, label: string}> The Blueprints whose objects can be listed. */
    private function listable(): array
    {
        $listable = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            if (!isset($blueprint->capabilities['authenticatable']) && !isset($blueprint->capabilities['submitted'])) {
                $listable[] = ['name' => $name, 'label' => $blueprint->label];
            }
        }

        return $listable;
    }

    private function redirect(Request $request, string $name): Response
    {
        return Response::redirect($request->basePath . $this->access->path('system/lists/' . $name), 303);
    }

    private static function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\Layout\LayoutColumn;
use Campanella\Layout\LayoutDefinition;
use Campanella\Layout\LayoutRow;
use Campanella\Layout\LayoutService;
use Campanella\Layout\LayoutStore;
use Campanella\Layout\RegionDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Security\Csrf;
use Closure;

/**
 * The layouts made in the admin (since 0.4.10), Appearance → Layouts, for the system roles:
 *
 *   /admin/system/layouts/new[?from=<name>]   a new layout, empty or a copy of a layout of rows
 *   /admin/system/layouts/<name>              one made in the admin, edited
 *   /admin/system/layouts/<name>/delete       deleted (not while the site or a Blueprint uses it)
 *
 * The form is the layout's rows of columns: each column a region (its name, label, width,
 * order when stacked, element, look on small screens, whether a page's own components may be
 * in it); a row's breakpoint, full width, and a proportion to set its columns by. Its buttons
 * (a row or a column added, taken out, moved) send the form and show it again changed, nothing
 * saved: it works without JavaScript; with it (admin-layout-editor.js) rows and columns are
 * dragged, and the sketch beside it, at a large and a small width, follows each change. Saved,
 * it is checked as a layout of rows (LayoutDefinition::fromJson()) and stored (LayoutStore).
 *
 * @phpstan-type Column array{region: string, label: string, width: int, order: string, landmark: string, look: string, page: bool}
 * @phpstan-type Row array{breakpoint: string, full: bool, preset: string, columns: list<Column>}
 * @phpstan-type Form array{name: string, label: string, rows: list<Row>}
 */
final class LayoutEditor
{
    /** The proportions offered for a row: its columns' widths of 12. */
    public const array PRESETS = ['12', '6-6', '8-4', '4-8', '9-3', '3-9', '4-4-4', '3-6-3', '3-3-3-3'];

    private const string REGION_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/D';

    public function __construct(
        private readonly LayoutService $layouts,
        private readonly LayoutStore $store,
        private readonly BlueprintRegistry $blueprints,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
        /** @var Closure(): list<string> The names of the modules there are (a layout's name cannot start with one's prefix) */
        private readonly Closure $modules,
        private readonly ?\Campanella\I18n\Translator $translator = null,
    ) {
    }

    /**
     * Where a layout is used: 'site' and the Blueprints' names.
     *
     * @return list<string>
     */
    public function usedBy(string $name): array
    {
        $used = $this->layouts->site()->name === $name ? ['site'] : [];
        foreach ($this->blueprints->all() as $blueprint) {
            if (isset($blueprint->capabilities['routable']) && $this->layouts->blueprintChoice($blueprint->name) === $name) {
                $used[] = $blueprint->name;
            }
        }

        return $used;
    }

    /**
     * A layout's rows for a sketch: each column's region, label, width, order and whether it is
     * hidden on small screens.
     *
     * @return list<array{full: bool, breakpoint: string, columns: list<array{region: string, label: string, width: int, order: ?int, hidden: bool}>}>
     */
    public static function sketch(LayoutDefinition $layout): array
    {
        return array_map(static fn (LayoutRow $row): array => [
            'full' => $row->full,
            'breakpoint' => $row->breakpoint,
            'columns' => array_map(static fn (LayoutColumn $c): array => [
                'region' => $c->region,
                'label' => $layout->regions[$c->region]->label ?? $c->region,
                'width' => $c->width,
                'order' => $c->order,
                'hidden' => ($layout->regions[$c->region]->small ?? '') === RegionDefinition::HIDE,
            ], $row->columns),
        ], $layout->rows);
    }

    /**
     * @param list<string> $segments The path after /admin/system/layouts
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        $registry = $this->layouts->registry();
        if ($segments === ['new']) {
            $from = $request->query['from'] ?? null;
            $copied = is_string($from) && $registry->has($from) && $registry->get($from)->hasRows() ? $registry->get($from) : null;

            return $this->form($request, null, $copied, $render);
        }
        $layout = $registry->has($segments[0]) ? $registry->get($segments[0]) : null;
        if ($layout === null || $layout->origin !== 'admin' || count($segments) > 2 || (isset($segments[1]) && $segments[1] !== 'delete')) {
            throw HttpException::notFound();
        }
        if (isset($segments[1])) {
            return $this->delete($request, $layout, $render);
        }

        return $this->form($request, $layout, null, $render);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function form(Request $request, ?LayoutDefinition $layout, ?LayoutDefinition $copied, Closure $render): Response
    {
        $form = $request->isPost() ? $this->posted($request) : $this->fromLayout($layout ?? $copied, $layout === null && $copied !== null);
        if ($layout !== null) {
            $form['name'] = $layout->name;
        }
        $errors = [];
        $status = 200;
        if ($request->isPost()) {
            if (!$this->csrf->isValid($request)) {
                return $this->show($render, $layout, $form, [], 'auth.form_expired', 400);
            }
            $op = $request->postString('op');
            if ($op !== 'save') {
                return $this->show($render, $layout, $this->operate($form, $op), [], null, 200);
            }
            $errors = $this->problems($form, $layout === null);
            if ($errors === []) {
                try {
                    $definition = LayoutDefinition::fromJson($form['name'], (string) json_encode($this->definition($form)), 'admin', false);
                    $this->store->save($definition);
                    $this->layouts->registry()->reset();
                    $this->flash->add(Flash::SUCCESS, new Message('layouts.editor.saved', ['label' => $definition->label]));

                    return Response::redirect($request->basePath . $this->access->path('system/layouts/' . $definition->name), 303);
                } catch (\InvalidArgumentException $e) {
                    $errors['form'] = new Message('layouts.editor.error.invalid', ['reason' => $e->getMessage()]);
                }
            }
            $status = 422;
        }

        return $this->show($render, $layout, $form, $errors, $errors === [] ? null : 'admin.form.invalid', $status);
    }

    /**
     * @param Form $form
     * @param array<string, Message> $errors
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function show(Closure $render, ?LayoutDefinition $layout, array $form, array $errors, ?string $alert, int $status): Response
    {
        $registry = $this->layouts->registry();
        $regions = array_values(array_diff($registry->regionNames(), array_keys(LayoutDefinition::FIXED)));
        sort($regions);
        $response = $render('layout_form', 'layouts', [
            'title' => $layout === null ? 'layouts.editor.new' : 'layouts.editor.edit',
            'layout' => $layout,
            'form' => $form,
            'errors' => $errors,
            'alert' => $alert,
            'presets' => self::PRESETS,
            'breakpoints' => RegionDefinition::BREAKPOINTS,
            'looks' => LayoutPages::looks(),
            'regions' => $regions,
            'max_rows' => LayoutDefinition::MAX_ROWS,
            'max_columns' => LayoutRow::MAX_COLUMNS,
            'sketch' => $this->formSketch($form),
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function delete(Request $request, LayoutDefinition $layout, Closure $render): Response
    {
        $used = $this->usedBy($layout->name);
        if ($request->isPost()) {
            if (!$this->csrf->isValid($request)) {
                $this->flash->add(Flash::DANGER, 'auth.form_expired');
            } elseif ($used === []) {
                $this->store->delete($layout->name);
                $this->layouts->registry()->reset();
                $this->flash->add(Flash::SUCCESS, new Message('layouts.editor.deleted', ['label' => $layout->label]));

                return Response::redirect($request->basePath . $this->access->path('system/layouts'), 303);
            }
        }

        return $render('layout_delete', 'layouts', ['title' => 'layouts.editor.delete', 'layout' => $layout, 'used' => $used]);
    }

    /**
     * The form of a layout (or an empty one: one row, the content).
     *
     * @return Form
     */
    private function fromLayout(?LayoutDefinition $layout, bool $copy): array
    {
        if ($layout === null) {
            return ['name' => '', 'label' => '', 'rows' => [['breakpoint' => 'lg', 'full' => false, 'preset' => '', 'columns' => [self::column('content', '', 12)]]]];
        }
        $rows = [];
        foreach ($layout->rows as $row) {
            $columns = [];
            foreach ($row->columns as $column) {
                $region = $layout->regions[$column->region];
                $columns[] = [
                    'region' => $column->region,
                    // A label that is a message key (the core's) is shown, and saved, as its text.
                    'label' => $this->translator?->translate($region->label) ?? $region->label,
                    'width' => $column->width,
                    'order' => $column->order === null ? '' : (string) $column->order,
                    'landmark' => $column->landmark,
                    'look' => $region->small === RegionDefinition::ALWAYS ? RegionDefinition::ALWAYS : $region->small . ':' . $region->breakpoint,
                    'page' => $region->page,
                ];
            }
            $rows[] = ['breakpoint' => $row->breakpoint, 'full' => $row->full, 'preset' => '', 'columns' => $columns];
        }

        return ['name' => $copy ? '' : $layout->name, 'label' => $copy ? '' : $layout->label, 'rows' => $rows];
    }

    /** @return Column */
    private static function column(string $region, string $label, int $width): array
    {
        return ['region' => $region, 'label' => $label, 'width' => $width, 'order' => '', 'landmark' => LayoutColumn::NONE, 'look' => RegionDefinition::ALWAYS, 'page' => $region === LayoutDefinition::CONTENT];
    }

    /**
     * The form as sent, rows and columns in the order of their `position` (as dragged); at
     * most MAX_ROWS rows of MAX_COLUMNS columns are read.
     *
     * @return Form
     */
    private function posted(Request $request): array
    {
        $sent = is_array($request->post['row'] ?? null) ? $request->post['row'] : [];
        $rows = [];
        foreach (array_slice($sent, 0, LayoutDefinition::MAX_ROWS, true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $columns = [];
            foreach (array_slice(is_array($row['col'] ?? null) ? $row['col'] : [], 0, LayoutRow::MAX_COLUMNS, true) as $column) {
                if (!is_array($column)) {
                    continue;
                }
                $look = self::text($column['look'] ?? '');
                $columns[] = [(int) self::text($column['position'] ?? '0'), [
                    'region' => trim(self::text($column['region'] ?? '')),
                    'label' => trim(self::text($column['label'] ?? '')),
                    'width' => max(1, min(LayoutRow::GRID, (int) self::text($column['width'] ?? '12'))),
                    'order' => self::text($column['order'] ?? ''),
                    'landmark' => self::text($column['landmark'] ?? '') === LayoutColumn::ASIDE ? LayoutColumn::ASIDE : LayoutColumn::NONE,
                    'look' => isset(LayoutPages::looks()[$look]) ? $look : RegionDefinition::ALWAYS,
                    'page' => self::text($column['page'] ?? '') === '1',
                ]];
            }
            usort($columns, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $breakpoint = self::text($row['breakpoint'] ?? 'lg');
            $preset = self::text($row['preset'] ?? '');
            $rows[] = [(int) self::text($row['position'] ?? '0'), [
                'breakpoint' => in_array($breakpoint, RegionDefinition::BREAKPOINTS, true) ? $breakpoint : 'lg',
                'full' => self::text($row['full'] ?? '') === '1',
                'preset' => in_array($preset, self::PRESETS, true) ? $preset : '',
                'columns' => array_column($columns, 1),
            ]];
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $form = ['name' => trim($request->postString('name')), 'label' => trim($request->postString('label')), 'rows' => array_column($rows, 1)];

        return $this->applyPresets($form);
    }

    /**
     * A row's proportion chosen: its columns' widths, and as many columns.
     *
     * @param Form $form
     * @return Form
     */
    private function applyPresets(array $form): array
    {
        foreach ($form['rows'] as $i => $row) {
            if ($row['preset'] === '') {
                continue;
            }
            $columns = [];
            foreach (array_map('intval', explode('-', $row['preset'])) as $j => $width) {
                $columns[] = ['width' => $width] + ($row['columns'][$j] ?? self::column('', '', $width));
            }
            $form['rows'][$i]['columns'] = $columns;
            $form['rows'][$i]['preset'] = '';
        }

        return $form;
    }

    /**
     * A button of the form: a row or a column added, taken out or moved.
     *
     * @param Form $form
     * @return Form
     */
    private function operate(array $form, string $op): array
    {
        $parts = explode(':', $op);
        $i = (int) ($parts[1] ?? -1);
        $j = (int) ($parts[2] ?? -1);
        $rows = $form['rows'];
        switch ($parts[0]) {
            case 'add_row':
                if (count($rows) < LayoutDefinition::MAX_ROWS) {
                    $rows[] = ['breakpoint' => 'lg', 'full' => false, 'preset' => '', 'columns' => [self::column('', '', 12)]];
                }
                break;
            case 'remove_row':
                if (isset($rows[$i]) && count($rows) > 1) {
                    array_splice($rows, $i, 1);
                }
                break;
            case 'row_up':
            case 'row_down':
                $to = $parts[0] === 'row_up' ? $i - 1 : $i + 1;
                if (isset($rows[$i], $rows[$to])) {
                    [$rows[$i], $rows[$to]] = [$rows[$to], $rows[$i]];
                }
                break;
            case 'add_column':
                if (isset($rows[$i]) && count($rows[$i]['columns']) < LayoutRow::MAX_COLUMNS) {
                    $used = 0;
                    foreach ($rows[$i]['columns'] as $column) {
                        $used += $column['width'];
                    }
                    $rows[$i]['columns'][] = self::column('', '', max(1, LayoutRow::GRID - $used));
                }
                break;
            case 'remove_column':
                if (isset($rows[$i]['columns'][$j]) && count($rows[$i]['columns']) > 1) {
                    array_splice($rows[$i]['columns'], $j, 1);
                }
                break;
            case 'column_left':
            case 'column_right':
                $to = $parts[0] === 'column_left' ? $j - 1 : $j + 1;
                if (isset($rows[$i]['columns'][$j], $rows[$i]['columns'][$to])) {
                    [$rows[$i]['columns'][$j], $rows[$i]['columns'][$to]] = [$rows[$i]['columns'][$to], $rows[$i]['columns'][$j]];
                }
                break;
        }
        $form['rows'] = $rows;

        return $form;
    }

    /**
     * What is wrong with the form, by field: `name`, `label`, `row.<i>`, `column.<i>.<j>`.
     *
     * @param Form $form
     * @return array<string, Message>
     */
    private function problems(array $form, bool $new): array
    {
        $errors = [];
        $registry = $this->layouts->registry();
        if ($new) {
            $name = (string) $form['name'];
            if (preg_match(LayoutDefinition::NAME_PATTERN, $name) !== 1) {
                $errors['name'] = new Message('layouts.editor.error.name');
            } elseif ($registry->isFileName($name)) {
                $errors['name'] = new Message('layouts.editor.error.name_file');
            } elseif ($registry->has($name) || isset($this->store->load()[$name])) {
                $errors['name'] = new Message('layouts.editor.error.name_taken');
            } else {
                foreach (($this->modules)() as $module) {
                    if (str_starts_with($name, $module . '_')) {
                        $errors['name'] = new Message('layouts.editor.error.name_module', ['prefix' => $module . '_']);
                        break;
                    }
                }
            }
        }
        if ($form['label'] === '' || mb_strlen((string) $form['label'], 'UTF-8') > 100) {
            $errors['label'] = new Message('layouts.editor.error.label');
        }
        $seen = [];
        $content = 0;
        foreach ($form['rows'] as $i => $row) {
            $orders = [];
            foreach ($row['columns'] as $j => $column) {
                $key = 'column.' . $i . '.' . $j;
                $region = $column['region'];
                if (preg_match(self::REGION_PATTERN, $region) !== 1) {
                    $errors[$key] = new Message('layouts.editor.error.region');
                } elseif (isset(LayoutDefinition::FIXED[$region])) {
                    $errors[$key] = new Message('layouts.editor.error.region_fixed');
                } elseif (isset($seen[$region])) {
                    $errors[$key] = new Message('layouts.editor.error.region_twice');
                } elseif ($region !== LayoutDefinition::CONTENT && ($column['label'] === '' || mb_strlen($column['label'], 'UTF-8') > 100)) {
                    $errors[$key] = new Message('layouts.editor.error.region_label');
                } elseif ($column['order'] !== '' && (preg_match('/^[1-4]$/D', $column['order']) !== 1 || isset($orders[$column['order']]))) {
                    $errors[$key] = new Message('layouts.editor.error.order', ['max' => LayoutRow::MAX_COLUMNS]);
                }
                $seen[$region] = true;
                $content += $region === LayoutDefinition::CONTENT ? 1 : 0;
                if ($column['order'] !== '') {
                    $orders[$column['order']] = true;
                }
            }
            $width = array_sum(array_column($row['columns'], 'width'));
            if ($width > LayoutRow::GRID) {
                $errors['row.' . $i] = new Message('layouts.editor.error.width', ['width' => $width]);
            }
        }
        if ($content !== 1) {
            $errors['form'] = new Message('layouts.editor.error.content');
        }

        return $errors;
    }

    /**
     * The form as a layout's JSON (LayoutDefinition::fromJson() checks it again).
     *
     * @param Form $form
     * @return array<string, mixed>
     */
    private function definition(array $form): array
    {
        $rows = [];
        $regions = [];
        foreach ($form['rows'] as $row) {
            $columns = [];
            foreach ($row['columns'] as $column) {
                $columns[] = array_filter([
                    'region' => $column['region'],
                    'width' => $column['width'],
                    'order' => $column['order'] === '' ? null : (int) $column['order'],
                    'landmark' => $column['landmark'] === LayoutColumn::ASIDE ? LayoutColumn::ASIDE : null,
                ], static fn ($v): bool => $v !== null);
                $look = LayoutPages::looks()[$column['look']] ?? LayoutPages::looks()[RegionDefinition::ALWAYS];
                $regions[$column['region']] = array_filter([
                    'label' => $column['region'] === LayoutDefinition::CONTENT && $column['label'] === '' ? 'layout.region.content' : $column['label'],
                    'small' => $look['breakpoint'] === null ? null : $look['small'],
                    'breakpoint' => $look['breakpoint'],
                    'page' => $column['page'] ? true : null,
                ], static fn ($v): bool => $v !== null);
            }
            $rows[] = array_filter(['breakpoint' => $row['breakpoint'], 'full' => $row['full'] ? true : null, 'columns' => $columns], static fn ($v): bool => $v !== null);
        }

        return ['version' => LayoutDefinition::VERSION, 'label' => $form['label'], 'rows' => $rows, 'regions' => $regions];
    }

    /**
     * The form's sketch, as sketch() gives a layout's.
     *
     * @param Form $form
     * @return list<array<string, mixed>>
     */
    private function formSketch(array $form): array
    {
        return array_map(static fn (array $row): array => [
            'full' => $row['full'],
            'breakpoint' => $row['breakpoint'],
            'columns' => array_map(static fn (array $c): array => [
                'region' => $c['region'],
                'label' => $c['label'] !== '' ? $c['label'] : ($c['region'] === LayoutDefinition::CONTENT ? 'layout.region.content' : $c['region']),
                'width' => $c['width'],
                'order' => $c['order'] === '' ? null : (int) $c['order'],
                'hidden' => str_starts_with($c['look'], RegionDefinition::HIDE . ':'),
            ], $row['columns']),
        ], $form['rows']);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}

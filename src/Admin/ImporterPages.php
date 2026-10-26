<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Capability\Authenticatable;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Import\Importer;
use Campanella\Import\ImporterDefinition;
use Campanella\Import\ImporterStore;
use Campanella\Import\ImportException;
use Campanella\Import\ImportMapping;
use Campanella\Import\ImportRun;
use Campanella\Import\ImportTrial;
use Campanella\Model\BlueprintDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\Relation;
use Campanella\Security\Csrf;
use Closure;

/**
 * The importers in the admin (since 0.3.1), under /admin/system/importers, for the
 * system roles:
 *
 * - the overview: every importer, its Blueprint, its last run;
 * - `new`: a new importer (its name, Blueprint, format, the key's and the title's
 *   columns), then `<name>`: its settings and mapping; `<name>/delete`;
 * - `<name>/run`: uploading a file (at most MAX_UPLOAD) shows a trial of its first
 *   items; `<name>/start` starts the run of the file the trial showed; the runs' log;
 * - `<name>/runs/<id>`: a run's progress and log; `…/step` goes on with it (the
 *   page does so by itself while it runs), `…/cancel`, `…/undo`.
 *
 * An uploaded file waits for the confirmation in var/imports/ (deleted when the run
 * starts, or after PENDING_HOURS), never in a public folder.
 */
final class ImporterPages
{
    /** The largest file uploaded in the admin (the command line takes any). */
    public const int MAX_UPLOAD = 5 * 1024 * 1024;

    /** The empty mapping rows of the form, besides the importer's own. */
    public const int EMPTY_ROWS = 8;

    /** The seconds a step of a run in the admin takes at most. */
    public const float STEP_SECONDS = 8.0;

    /** An uploaded file not confirmed is deleted after this many hours. */
    public const int PENDING_HOURS = 2;

    public function __construct(
        private readonly Importer $importer,
        private readonly ImporterStore $store,
        private readonly BlueprintRegistry $blueprints,
        private readonly QueryEngine $queries,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
        private readonly Translator $translator,
        private readonly string $directory,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/importers
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        $name = $segments[0] ?? null;
        $rest = array_slice($segments, 1);

        return match (true) {
            $segments === [] => $this->overview($render),
            $segments === ['new'] => $this->create($request, $actor, $render),
            $rest === [] => $this->edit($request, (string) $name, $render),
            $rest === ['delete'] => $this->delete($request, (string) $name),
            $rest === ['run'] => $this->upload($request, $this->definition((string) $name), $render),
            $rest === ['start'] => $this->start($request, $actor, $this->definition((string) $name)),
            count($rest) === 2 && $rest[0] === 'runs' && ctype_digit($rest[1]) => $this->log($this->definition((string) $name), (int) $rest[1], $render),
            count($rest) === 3 && $rest[0] === 'runs' && ctype_digit($rest[1]) && in_array($rest[2], ['step', 'cancel', 'undo'], true)
                => $this->action($request, $this->definition((string) $name), (int) $rest[1], $rest[2]),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function overview(Closure $render): Response
    {
        [$importers, $errors] = $this->importer->all();
        $rows = [];
        foreach ($importers as $definition) {
            $last = $this->importer->runs($definition->name, 1)[0] ?? null;
            $rows[] = [
                'name' => $definition->name,
                'label' => $definition->label,
                'blueprint' => $this->blueprints->get($definition->blueprint)->label,
                'format' => $definition->format,
                'objects' => $this->importer->objectCount($definition->name),
                'last' => $last === null ? null : $this->runView($last),
            ];
        }

        return $render('importers', 'importers', ['title' => 'importers.title', 'importers' => $rows, 'errors' => $errors]);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function create(Request $request, Actor $actor, Closure $render): Response
    {
        $form = ['name' => '', 'label' => '', 'blueprint' => '', 'format' => 'csv', 'key' => '', 'title' => ''];
        $errors = [];
        if ($request->isPost()) {
            foreach (array_keys($form) as $key) {
                $form[$key] = trim($request->postString($key));
            }
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
                    $blueprint = $this->blueprints->find($form['blueprint']);
                    $mapping = $blueprint !== null && isset($blueprint->capabilities['titled']) ? [['source' => $form['title'], 'target' => 'title']] : [];
                    try {
                        $definition = ImporterDefinition::fromArray($form['name'], [
                            'label' => $form['label'], 'blueprint' => $form['blueprint'], 'format' => $form['format'],
                            'key' => $form['key'], 'mapping' => $mapping, 'user' => (int) $actor->id,
                        ], $this->blueprints);
                        $this->store->save($definition->name, $definition->toArray());
                        $this->flash->add(Flash::SUCCESS, new Message('importers.created', ['label' => $definition->label]));

                        return $this->redirect($request, $definition->name);
                    } catch (\InvalidArgumentException $e) {
                        $errors['form'] = new Message('importers.invalid', ['reason' => $e->getMessage()]);
                    }
                }
            }
        }
        $response = $render('importer_new', 'importers', [
            'title' => 'importers.new',
            'form' => $form,
            'errors' => $errors,
            'blueprints' => $this->importable(),
            'formats' => ImporterDefinition::FORMATS,
        ]);

        return $request->isPost() ? self::status($response, 422) : $response;
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function edit(Request $request, string $name, Closure $render): Response
    {
        $stored = $this->store->find($name) ?? throw HttpException::notFound();
        $definition = $this->importer->find($name);
        $blueprintName = is_string($stored['blueprint'] ?? null) ? $stored['blueprint'] : '';
        $blueprint = $this->blueprints->find($blueprintName);
        if ($blueprint === null) {
            // Its Blueprint is gone: it can only be deleted.
            return $render('importer_edit', 'importers', ['title' => 'importers.edit', 'importer' => ['name' => $name, 'label' => (string) ($stored['label'] ?? $name), 'blueprint' => $blueprintName], 'broken' => true, 'errors' => []]);
        }
        $errors = [];
        $form = $stored;
        if ($request->isPost()) {
            $form = $this->posted($request, $blueprint->name);
            if (!$this->csrf->isValid($request)) {
                $errors['form'] = new Message('auth.form_expired');
            } else {
                try {
                    $definition = ImporterDefinition::fromArray($name, $form, $this->blueprints);
                    $this->importer->actor($definition);
                    $this->store->save($name, $definition->toArray());
                    $this->flash->add(Flash::SUCCESS, new Message('importers.saved', ['label' => $definition->label]));

                    return $this->redirect($request, $name);
                } catch (\InvalidArgumentException $e) {
                    $errors['form'] = new Message('importers.invalid', ['reason' => $e->getMessage()]);
                } catch (ImportException $e) {
                    $errors['form'] = $e->reason;
                }
            }
        } elseif ($definition === null) {
            try {
                ImporterDefinition::fromArray($name, $stored, $this->blueprints);
            } catch (\InvalidArgumentException $e) {
                $errors['form'] = new Message('importers.invalid', ['reason' => $e->getMessage()]);
            }
        }
        $mapping = array_values(array_filter((array) ($form['mapping'] ?? []), is_array(...)));
        for ($i = 0; $i < self::EMPTY_ROWS; $i++) {
            $mapping[] = ['source' => '', 'target' => '', 'separator' => '|'];
        }
        $response = $render('importer_edit', 'importers', [
            'title' => 'importers.edit',
            'importer' => ['name' => $name, 'label' => (string) ($form['label'] ?? $name), 'blueprint' => $blueprint->label, 'objects' => $this->importer->objectCount($name)],
            'broken' => false,
            'form' => $form + ['format' => 'csv', 'delimiter' => 'auto', 'items' => '', 'key' => '', 'publish' => false, 'missing_target' => 'error', 'missing_items' => 'keep', 'user' => 0],
            'mapping' => $mapping,
            'targets' => array_map(static fn (Field|Relation $t): array => [
                'name' => $t->name,
                'label' => $t->label !== '' ? $t->label : $t->name,
                'type' => $t instanceof Relation ? 'relation' : $t->type->value,
                'multiple' => $t instanceof Relation ? $t->isMany() : $t->isMultiple(),
            ], array_values(ImporterDefinition::targets($blueprint))),
            'publishable' => isset($blueprint->capabilities['publishable']),
            'users' => $this->users(),
            'formats' => ImporterDefinition::FORMATS,
            'delimiters' => ImporterDefinition::DELIMITERS,
            'missing_target_choices' => ImporterDefinition::MISSING_TARGET,
            'missing_item_choices' => ImporterDefinition::MISSING_ITEMS,
            'errors' => $errors,
        ]);

        return $errors !== [] && $request->isPost() ? self::status($response, 422) : $response;
    }

    /**
     * The form's values, in the stored form (checked by ImporterDefinition::fromArray()).
     *
     * @return array<string, mixed>
     */
    private function posted(Request $request, string $blueprint): array
    {
        $mapping = [];
        $rows = $request->post['mapping'] ?? [];
        foreach (is_array($rows) ? array_slice($rows, 0, ImporterDefinition::MAX_MAPPINGS + self::EMPTY_ROWS) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = trim(is_string($row['source'] ?? null) ? $row['source'] : '');
            $target = is_string($row['target'] ?? null) ? $row['target'] : '';
            if ($source === '' && $target === '') {
                continue; // an empty row
            }
            $separator = is_string($row['separator'] ?? null) && $row['separator'] !== '' ? $row['separator'] : '|';
            $mapping[] = (new ImportMapping($source, $target, $separator === '\t' ? "\t" : $separator))->toArray();
        }

        return [
            'version' => ImporterDefinition::VERSION,
            'label' => trim($request->postString('label')),
            'blueprint' => $blueprint,
            'format' => $request->postString('format'),
            'delimiter' => $request->postString('delimiter'),
            'items' => trim($request->postString('items')),
            'key' => trim($request->postString('key')),
            'mapping' => $mapping,
            'publish' => $request->postString('publish') === '1',
            'missing_target' => $request->postString('missing_target'),
            'missing_items' => $request->postString('missing_items'),
            'user' => ctype_digit($request->postString('user')) ? (int) $request->postString('user') : 0,
        ];
    }

    private function delete(Request $request, string $name): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if ($this->store->find($name) === null) {
            throw HttpException::notFound();
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $this->redirect($request, $name);
        }
        if ($request->postString('confirm') !== '1') {
            $this->flash->add(Flash::DANGER, 'blueprints.delete_confirm_needed');

            return $this->redirect($request, $name);
        }
        $this->importer->delete($name);
        $this->flash->add(Flash::SUCCESS, 'importers.deleted');

        return Response::redirect($request->basePath . $this->access->path('system/importers'), 303);
    }

    /**
     * The run page: GET the upload form and the log; POST a file: its trial.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function upload(Request $request, ImporterDefinition $definition, Closure $render): Response
    {
        $context = ['trial' => null, 'token' => null, 'file_name' => null, 'alert' => null];
        $status = 200;
        if ($request->isPost()) {
            $status = 422;
            $file = $request->file('file');
            if (!$this->csrf->isValid($request)) {
                $context['alert'] = $this->translator->translate('auth.form_expired');
                $status = 400;
            } elseif ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
                $context['alert'] = $this->translator->translate('model.no_file');
            } elseif ($file->isTooLarge() || $file->size > self::MAX_UPLOAD || !$file->isOk() || !is_file($file->path)) {
                $context['alert'] = $this->translator->translate($file->isTooLarge() || $file->size > self::MAX_UPLOAD ? 'importers.too_large' : 'model.upload_failed', ['size' => (int) (self::MAX_UPLOAD / 1048576) . ' MB']);
            } else {
                try {
                    $trial = $this->importer->trial($definition, $file->path);
                    $token = $this->keep($file->path, $definition->format);
                    $context = ['trial' => $this->trialView($trial, $definition), 'token' => $token, 'file_name' => mb_substr(basename($file->name), 0, 255, 'UTF-8'), 'alert' => null];
                    $status = 200;
                } catch (ImportException $e) {
                    $context['alert'] = ImportRun::text($e->reason, $this->translator);
                }
            }
        }
        $response = $render('importer_run', 'importers', [
            'title' => 'importers.run',
            'importer' => ['name' => $definition->name, 'label' => $definition->label, 'format' => $definition->format],
            'runs' => array_map($this->runView(...), $this->importer->runs($definition->name)),
            'max_bytes' => self::MAX_UPLOAD,
        ] + $context);

        return $status === 200 ? $response : self::status($response, $status);
    }

    /** POST: starts the run of the file the trial showed. */
    private function start(Request $request, Actor $actor, ImporterDefinition $definition): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = Response::redirect($request->basePath . $this->access->path('system/importers/' . $definition->name . '/run'), 303);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        $path = $this->pending($request->postString('token'), $definition->format);
        if ($path === null) {
            $this->flash->add(Flash::DANGER, 'importers.file_gone');

            return $back;
        }
        try {
            $run = $this->importer->start($definition, $path, $request->postString('file_name') !== '' ? $request->postString('file_name') : basename($path), $actor->id);
        } catch (ImportException $e) {
            $this->flash->add(Flash::DANGER, ImportRun::text($e->reason, $this->translator));

            return $back;
        } finally {
            @unlink($path);
        }

        return $this->runRedirect($request, $definition, $run->id);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function log(ImporterDefinition $definition, int $id, Closure $render): Response
    {
        $run = $this->importer->run($id);
        if ($run === null || $run->importer !== $definition->name) {
            throw HttpException::notFound();
        }

        return $render('importer_log', 'importers', [
            'title' => 'importers.log',
            'importer' => ['name' => $definition->name, 'label' => $definition->label],
            'run' => $this->runView($run),
        ]);
    }

    /** POST: a run's next step, stopping it, or undoing it. */
    private function action(Request $request, ImporterDefinition $definition, int $id, string $action): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $run = $this->importer->run($id);
        if ($run === null || $run->importer !== $definition->name) {
            throw HttpException::notFound();
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $this->runRedirect($request, $definition, $id);
        }
        try {
            if ($action === 'step') {
                $this->step($id);
            } elseif ($action === 'cancel') {
                $this->importer->cancel($id);
            } else {
                $this->undo($request, $id);
            }
        } catch (ImportException $e) {
            $this->flash->add(Flash::DANGER, ImportRun::text($e->reason, $this->translator));
        }

        return $this->runRedirect($request, $definition, $id);
    }

    private function step(int $id): void
    {
        @set_time_limit((int) self::STEP_SECONDS * 4 + 30);
        $this->importer->step($id, self::STEP_SECONDS);
    }

    private function undo(Request $request, int $id): void
    {
        if ($request->postString('confirm') !== '1') {
            $this->flash->add(Flash::DANGER, 'importers.undo_confirm_needed');

            return;
        }
        @set_time_limit(300);
        $this->flash->add(Flash::SUCCESS, new Message('import.run.undone', ['count' => $this->importer->undo($id)]));
    }

    /**
     * A run, as the pages show it: its counts, and its errors in the reader's language.
     *
     * @return array<string, mixed>
     */
    private function runView(ImportRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'running' => $run->isRunning(),
            'source' => $run->sourceName,
            'position' => $run->position,
            'total' => $run->total,
            'percent' => $run->percent(),
            'created' => $run->created,
            'updated' => $run->updated,
            'unchanged' => $run->unchanged,
            'failed' => $run->failed,
            'missing' => $run->missing,
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
            'undone_at' => $run->undoneAt,
            'undoable' => !$run->isRunning() && $run->status !== ImportRun::UNDONE && $run->created > 0,
            'errors' => array_map(fn (array $e): array => [
                'row' => $e['row'],
                'key' => $e['key'],
                'field' => $e['field'],
                'text' => ImportRun::text($e['message'], $this->translator),
            ], $run->errors),
            'more_errors' => max(0, $run->failed - count($run->errors)),
        ];
    }

    /** @return array<string, mixed> */
    private function trialView(ImportTrial $trial, ImporterDefinition $definition): array
    {
        $labels = [];
        foreach (ImporterDefinition::targets($this->blueprints->get($definition->blueprint)) as $name => $target) {
            $labels[$name] = $this->translator->translate($target->label !== '' ? $target->label : $name);
        }

        return [
            'total' => $trial->total,
            'columns' => $trial->columns,
            'missing' => $trial->missingSources,
            'rows' => array_map(fn (array $row): array => [
                'row' => $row['row'],
                'key' => $row['key'],
                'action' => $row['action'],
                'values' => array_combine(array_map(static fn (string $n): string => $labels[$n] ?? $n, array_keys($row['values'])), array_values($row['values'])),
                'creates' => $row['creates'],
                'error' => $row['error'] === null ? null : ($row['field'] !== '' ? $row['field'] . ': ' : '') . ImportRun::text($row['error'], $this->translator),
            ], $trial->rows),
            'shown' => count($trial->rows),
        ];
    }

    /** Keeps an uploaded file until its run is confirmed; its token. */
    private function keep(string $path, string $format): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new ImportException(new Message('import.error.unwritable'));
        }
        // Files left unconfirmed go after a while.
        foreach (glob($this->directory . '/pending-*') ?: [] as $old) {
            if (filemtime($old) < time() - self::PENDING_HOURS * 3600) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(24));
        if (!@copy($path, $this->directory . '/pending-' . $token . '.' . $format)) {
            throw new ImportException(new Message('import.error.unwritable'));
        }

        return $token;
    }

    /** The waiting file of a token, or null if there is none (any more). */
    private function pending(string $token, string $format): ?string
    {
        if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return null;
        }
        $path = $this->directory . '/pending-' . $token . '.' . $format;

        return is_file($path) ? $path : null;
    }

    private function definition(string $name): ImporterDefinition
    {
        return $this->importer->find($name) ?? throw HttpException::notFound();
    }

    /**
     * The Blueprints whose objects can be imported, by label.
     *
     * @return list<array{name: string, label: string}>
     */
    private function importable(): array
    {
        $list = [];
        foreach ($this->blueprints->all() as $blueprint) {
            if (ImporterDefinition::importable($blueprint)) {
                $list[] = ['name' => $blueprint->name, 'label' => $blueprint->label];
            }
        }

        return $list;
    }

    /**
     * The users an importer can work in the name of: those who can log in and have a role
     * of the admin (an administrator or an editor).
     *
     * @return list<array{id: int, name: string, roles: string}>
     */
    private function users(): array
    {
        $users = [];
        foreach ($this->queries->execute(Query::objects()->blueprint('user')->orderBy('title')->limit(500), Actor::system())->items as $user) {
            $auth = $user->as(Authenticatable::class);
            if ($auth->isActive() && $auth->roles() !== []) {
                $users[] = ['id' => (int) $user->id(), 'name' => (string) $user->get('title'), 'roles' => implode(', ', $auth->roles())];
            }
        }

        return $users;
    }

    private function redirect(Request $request, string $name): Response
    {
        return Response::redirect($request->basePath . $this->access->path('system/importers/' . $name), 303);
    }

    private function runRedirect(Request $request, ImporterDefinition $definition, int $id): Response
    {
        return Response::redirect($request->basePath . $this->access->path('system/importers/' . $definition->name . '/runs/' . $id), 303);
    }

    private static function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Publishable;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\SchemaReader;
use Campanella\I18n\Message;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CalendarDate;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Relation\Relation;
use Campanella\Service\ObjectService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Running the importers (since 0.3.1): a trial of a file's first items, a run in
 * batches (so a large file never holds one request too long), its log, and undoing
 * a run. Never started by a visitor's request: by hand in the admin
 * (System → Importers) or on the command line (`import:run`, e.g. from cron).
 *
 * An item's unique key finds the object a former run made of it (`import_items`): an
 * item whose values did not change is skipped (by a hash of them), one that changed
 * updates the object, a new one makes an object. The objects are made and changed
 * through the ObjectService, in the name and with the permissions of the importer's
 * user; every value goes through the field's checks and the HTML filter, as in the
 * admin. A run never deletes: an object whose item is no longer in the source is
 * kept, or made a draft (`missing_items`). Undoing a run deletes the objects it made
 * (after a backup); what it changed in older ones stays.
 */
final class Importer
{
    /** The most errors a run's log keeps (it counts all). */
    public const int MAX_ERRORS = 200;

    /** The items a trial shows. */
    public const int TRIAL_ITEMS = 10;

    /** The longest key, in characters. */
    public const int MAX_KEY = 191;

    /** @var array<string, int> A run's relation targets made or found, by Blueprint and title */
    private array $made = [];

    public function __construct(
        private readonly Connection $db,
        private readonly BlueprintRegistry $blueprints,
        private readonly ImporterStore $store,
        private readonly ObjectService $objects,
        private readonly ObjectRepository $repository,
        private readonly DatabaseBackup $backup,
        private readonly DateTimeZone $timezone,
        private readonly string $directory,
    ) {
    }

    /**
     * Every importer that loads, by name; and those that do not, with the reason.
     *
     * @return array{0: array<string, ImporterDefinition>, 1: array<string, string>}
     */
    public function all(): array
    {
        $importers = [];
        $errors = [];
        foreach ($this->store->load() as $name => $stored) {
            try {
                $importers[$name] = ImporterDefinition::fromArray($name, $stored, $this->blueprints);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        return [$importers, $errors];
    }

    /** An importer, or null if there is none of that name, or it does not load. */
    public function find(string $name): ?ImporterDefinition
    {
        return $this->all()[0][$name] ?? null;
    }

    /**
     * What the first items of a file would become, changing nothing.
     *
     * @throws ImportException if the file cannot be read
     */
    public function trial(ImporterDefinition $importer, string $file, int $items = self::TRIAL_ITEMS): ImportTrial
    {
        $source = ImportSource::open($file, $importer);
        $blueprint = $this->blueprints->get($importer->blueprint);
        $columns = $source->columns();
        $missing = [];
        if ($importer->format === 'csv') {
            foreach ([$importer->key, ...array_map(static fn (ImportMapping $m): string => $m->source, $importer->mapping)] as $wanted) {
                if (!in_array($wanted, $columns, true) && !in_array($wanted, $missing, true)) {
                    $missing[] = $wanted;
                }
            }
        }
        $rows = [];
        $seen = [];
        foreach ($source->items() as $number => $item) {
            if (count($rows) >= $items) {
                break;
            }
            $row = ['row' => $number, 'key' => is_array($item) ? mb_substr(trim(self::text(ImportSource::value($item, $importer->key))), 0, self::MAX_KEY, 'UTF-8') : '',
                'action' => ImportTrial::ERROR, 'values' => [], 'creates' => [], 'error' => null, 'field' => ''];
            try {
                if ($item instanceof ImportException) {
                    throw $item;
                }
                $prepared = $this->prepare($importer, $blueprint, $item);
                $row['key'] = $prepared['key'];
                $row['values'] = $prepared['display'];
                $row['creates'] = $prepared['creates'];
                if (isset($seen[$prepared['key']])) {
                    throw new ImportException(new Message('import.error.duplicate', ['key' => $prepared['key']]));
                }
                $seen[$prepared['key']] = true;
                $known = $this->item($importer->name, $prepared['key']);
                $object = $known === null ? null : $this->repository->find((int) $known['object_id']);
                $row['action'] = $object === null ? ImportTrial::CREATE : ($known['hash'] === $prepared['hash'] ? ImportTrial::UNCHANGED : ImportTrial::UPDATE);
                if ($row['action'] !== ImportTrial::UNCHANGED) {
                    $this->validate($blueprint, $prepared, $object);
                }
            } catch (ImportException $e) {
                $row['error'] = $e->reason;
            } catch (ValidationException $e) {
                $row['field'] = (string) array_key_first($e->errors);
                $row['error'] = array_values($e->errors)[0] ?? new Message('import.error.value');
            }
            $rows[] = $row;
        }

        return new ImportTrial($source->count(), $columns, $missing, $rows);
    }

    /**
     * Starts a run of a file: keeps a copy of it (in var/imports/) until the run ends, and
     * counts its items. The run then goes on with step().
     *
     * @param string $sourceName The file's name, for the log
     * @param int|null $userId Who started it (null: the command line)
     * @throws ImportException if the file cannot be read, or another run of the importer is not done
     */
    public function start(ImporterDefinition $importer, string $file, string $sourceName, ?int $userId = null): ImportRun
    {
        $this->actor($importer); // the importer's user must be able to work
        $source = ImportSource::open($file, $importer);
        $total = $source->count();
        if ($this->db->fetchValue('SELECT 1 FROM {import_runs} WHERE importer = :i AND status = :s', ['i' => $importer->name, 's' => ImportRun::RUNNING]) !== null) {
            throw new ImportException(new Message('import.error.running'));
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new ImportException(new Message('import.error.unwritable'));
        }
        $copy = bin2hex(random_bytes(12)) . '.' . $importer->format;
        if (!@copy($file, $this->directory . '/' . $copy)) {
            throw new ImportException(new Message('import.error.unwritable'));
        }
        $id = $this->db->insert(CoreSchema::IMPORT_RUNS, [
            'importer' => $importer->name,
            'status' => ImportRun::RUNNING,
            'file' => $copy,
            'source_name' => mb_substr(basename($sourceName), 0, 255, 'UTF-8'),
            'position' => 0,
            'total' => $total,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'missing' => 0,
            'errors' => '[]',
            'user_id' => $userId,
            'started_at' => self::now(),
        ]);

        return $this->run($id) ?? throw new \LogicException('The run was not saved.');
    }

    /**
     * Goes on with a run for at most about this many seconds (at least one item); when
     * the last item is done, it ends the run (the missing items, the file deleted).
     */
    public function step(int $runId, float $seconds = 10.0): ImportRun
    {
        $run = $this->run($runId) ?? throw new ImportException(new Message('import.error.no_run'));
        if (!$run->isRunning()) {
            return $run;
        }
        $importer = $this->find($run->importer);
        $file = $this->runFile($runId);
        if ($importer === null || $file === null || !is_file($file)) {
            $this->end($runId, ImportRun::CANCELLED, ['errors' => self::errorsJson($run, 0, '', '', new Message('import.error.gone'))]);

            return $this->run($runId) ?? $run;
        }
        $actor = $this->actor($importer);
        $blueprint = $this->blueprints->get($importer->blueprint);
        $deadline = microtime(true) + $seconds;
        $counts = ['created' => $run->created, 'updated' => $run->updated, 'unchanged' => $run->unchanged, 'failed' => $run->failed];
        $errors = array_map(static fn (array $e): array => ['row' => $e['row'], 'key' => $e['key'], 'field' => $e['field'], 'message' => $e['message']->key, 'params' => $e['message']->params], $run->errors);
        $position = $run->position;
        $finished = true;
        foreach (ImportSource::open($file, $importer)->items($position) as $number => $item) {
            if ($position > $run->position && microtime(true) > $deadline) {
                $finished = false;
                break;
            }
            $position = $number;
            $key = is_array($item) ? mb_substr(trim(self::text(ImportSource::value($item, $importer->key))), 0, self::MAX_KEY, 'UTF-8') : '';
            try {
                if ($item instanceof ImportException) {
                    throw $item;
                }
                $prepared = $this->prepare($importer, $blueprint, $item);
                $counts[$this->save($importer, $blueprint, $prepared, $runId, $actor)]++;
            } catch (ImportException | ValidationException | AccessDeniedException $e) {
                $counts['failed']++;
                if (count($errors) < self::MAX_ERRORS) {
                    [$field, $message] = match (true) {
                        $e instanceof ImportException => ['', $e->reason],
                        $e instanceof ValidationException => [(string) array_key_first($e->errors), array_values($e->errors)[0] ?? new Message('import.error.value')],
                        default => ['', new Message('import.error.denied')],
                    };
                    $errors[] = ['row' => $number, 'key' => $key, 'field' => $field, 'message' => $message->key, 'params' => $message->params];
                }
            }
        }
        $values = $counts + ['position' => $position, 'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'];
        if ($finished) {
            $values['missing'] = $this->missing($importer, $runId, $actor);
            $this->end($runId, ImportRun::DONE, $values);
        } else {
            $this->db->update(CoreSchema::IMPORT_RUNS, $values, ['id' => $runId]);
        }

        return $this->run($runId) ?? $run;
    }

    /** Stops a run that is not done: what it did stays (and can be undone). */
    public function cancel(int $runId): void
    {
        $run = $this->run($runId);
        if ($run !== null && $run->isRunning()) {
            $this->end($runId, ImportRun::CANCELLED, []);
        }
    }

    /**
     * Undoes a run that is not running: deletes the objects it made (a backup first, if
     * there are any). What it changed in objects made before stays.
     *
     * @return int How many objects were deleted
     * @throws ImportException if the run is running or was undone
     */
    public function undo(int $runId): int
    {
        $run = $this->run($runId) ?? throw new ImportException(new Message('import.error.no_run'));
        if ($run->isRunning() || $run->status === ImportRun::UNDONE) {
            throw new ImportException(new Message('import.error.not_undoable'));
        }
        $ids = array_map('intval', $this->db->fetchColumn(
            'SELECT object_id FROM {import_items} WHERE importer = :i AND created_run = :r',
            ['i' => $run->importer, 'r' => $runId],
        ));
        if ($ids !== []) {
            $this->backup->create();
        }
        $importer = $this->find($run->importer);
        $actor = $importer !== null ? $this->actor($importer) : Actor::system();
        $deleted = 0;
        foreach ($this->repository->loadMany($ids) as $object) {
            $this->objects->delete($actor, $object);
            $deleted++;
        }
        $this->db->update(CoreSchema::IMPORT_RUNS, ['status' => ImportRun::UNDONE, 'undone_at' => self::now()], ['id' => $runId]);

        return $deleted;
    }

    /**
     * An importer's runs, the newest first.
     *
     * @return list<ImportRun>
     */
    public function runs(string $importer, int $limit = 20): array
    {
        if (!$this->ready()) {
            return [];
        }

        return array_map(
            static fn (array $row): ImportRun => ImportRun::fromRow($row),
            $this->db->fetchAll('SELECT * FROM {import_runs} WHERE importer = :i ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), ['i' => $importer]),
        );
    }

    public function run(int $id): ?ImportRun
    {
        $row = $this->ready() ? $this->db->fetchOne('SELECT * FROM {import_runs} WHERE id = :id', ['id' => $id]) : null;

        return $row === null ? null : ImportRun::fromRow($row);
    }

    /** How many objects an importer's runs made that are still there. */
    public function objectCount(string $importer): int
    {
        return $this->ready() ? (int) $this->db->fetchValue('SELECT COUNT(*) FROM {import_items} WHERE importer = :i', ['i' => $importer]) : 0;
    }

    /** Deletes an importer, its log and its runs' files; the objects it made stay. */
    public function delete(string $importer): void
    {
        foreach ($this->db->fetchColumn('SELECT file FROM {import_runs} WHERE importer = :i AND file IS NOT NULL', ['i' => $importer]) as $file) {
            @unlink($this->directory . '/' . basename((string) $file));
        }
        $this->store->delete($importer);
    }

    /**
     * The actor of an importer's user.
     *
     * @throws ImportException if the user is not there, or cannot log in
     */
    public function actor(ImporterDefinition $importer): Actor
    {
        $user = $this->repository->find($importer->user);
        if ($user === null || !$user->has(Authenticatable::class) || !$user->as(Authenticatable::class)->isActive()) {
            throw new ImportException(new Message('import.error.user'));
        }

        return AuthService::actorFor($user);
    }

    /**
     * An item's key, values, relations and hash, converted and found; the relation
     * targets to make (`missing_target: create`) are only named here.
     *
     * @param array<string, mixed> $item
     * @return array{key: string, values: array<string, mixed>, relations: array<string, list<int>>, creates: list<string>, make: array<string, list<array{blueprint: string, title: string}>>, publish_at: ?DateTimeImmutable, hash: string, display: array<string, string>}
     * @throws ImportException
     */
    private function prepare(ImporterDefinition $importer, Blueprint $blueprint, array $item): array
    {
        $key = trim(self::text(ImportSource::value($item, $importer->key)));
        if ($key === '') {
            throw new ImportException(new Message('import.error.no_key', ['source' => $importer->key]));
        }
        if (mb_strlen($key, 'UTF-8') > self::MAX_KEY) {
            throw new ImportException(new Message('import.error.long_key', ['max' => self::MAX_KEY]));
        }
        $convert = new ImportValues($this->timezone);
        $values = [];
        $relations = [];
        $make = [];
        $creates = [];
        $publishAt = null;
        $display = [];
        $named = [];
        foreach ($importer->mapping as $mapping) {
            $raw = ImportSource::value($item, $mapping->source);
            $target = ImporterDefinition::target($blueprint, $mapping->target);
            if ($target instanceof Relation) {
                [$ids, $missing] = $this->targets($target, ImportValues::split($raw, $mapping->separator));
                foreach ($missing as $title) {
                    if ($importer->missingTarget === 'error') {
                        throw new ImportException(new Message('import.error.target', ['relation' => $target->label !== '' ? $target->label : $target->name, 'value' => mb_substr($title, 0, 50)]));
                    }
                    if ($importer->missingTarget === 'create') {
                        $make[$target->name][] = ['blueprint' => $target->targetBlueprints[0], 'title' => $title];
                        $creates[] = $title;
                    }
                }
                if (!$target->isMany() && count($ids) + count($make[$target->name] ?? []) > 1) {
                    throw new ImportException(new Message('import.error.one_target', ['relation' => $target->label !== '' ? $target->label : $target->name]));
                }
                $relations[$target->name] = $ids;
                $named[$target->name] = array_map(static fn (mixed $v): string => trim(self::text($v)), ImportValues::split($raw, $mapping->separator));
                $display[$target->name] = implode(', ', $named[$target->name]);
                continue;
            }
            /** @var Field $target */
            $value = $convert->convert($target, $raw, $mapping->separator);
            if ($target->name === 'published_at') {
                $publishAt = $value instanceof DateTimeImmutable ? $value : null;
            } elseif ($target->name === 'path' && $value === null) {
                // An empty path: made from the title (left out).
            } else {
                $values[$target->name] = $value;
            }
            // A choice by its label, as the editors see it.
            $shown = $target->type === FieldType::Choice
                ? (is_array($value) ? array_map(static fn (mixed $v): mixed => $target->choices[$v] ?? $v, $value) : ($target->choices[$value] ?? $value))
                : $value;
            $display[$target->name] = $this->show($shown);
        }
        if (isset($values['body'])) {
            $values['format'] = 'html'; // the converted body is HTML
        }
        ksort($named);
        // The hash of the item as the source has it (the relations by their values, not
        // by the IDs found), so an unchanged item stays unchanged.
        $hash = hash('sha256', (string) json_encode([
            array_map(self::normal(...), $values),
            $named,
            $publishAt?->format(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return ['key' => $key, 'values' => $values, 'relations' => $relations, 'creates' => $creates, 'make' => $make, 'publish_at' => $publishAt, 'hash' => $hash, 'display' => $display];
    }

    /**
     * Saves a prepared item: a new object, a changed one, or nothing.
     *
     * @param array{key: string, values: array<string, mixed>, relations: array<string, list<int>>, creates: list<string>, make: array<string, list<array{blueprint: string, title: string}>>, publish_at: ?DateTimeImmutable, hash: string, display: array<string, string>} $prepared
     * @return 'created'|'updated'|'unchanged'
     */
    private function save(ImporterDefinition $importer, Blueprint $blueprint, array $prepared, int $runId, Actor $actor): string
    {
        $known = $this->item($importer->name, $prepared['key']);
        if ($known !== null && (int) $known['last_run'] === $runId) {
            throw new ImportException(new Message('import.error.duplicate', ['key' => $prepared['key']]));
        }
        $object = $known === null ? null : $this->repository->find((int) $known['object_id']);
        if ($object !== null && $known['hash'] === $prepared['hash']) {
            $this->db->update(CoreSchema::IMPORT_ITEMS, ['last_run' => $runId], ['importer' => $importer->name, 'item_key' => $prepared['key']]);

            return 'unchanged';
        }

        return $this->db->transactional(function () use ($importer, $blueprint, $prepared, $runId, $actor, $object): string {
            $relations = $prepared['relations'];
            foreach ($prepared['make'] as $relation => $targets) {
                foreach ($targets as $target) {
                    $relations[$relation][] = $this->make($importer, $target['blueprint'], $target['title'], $actor);
                }
            }
            $publishable = isset($blueprint->capabilities['publishable']);
            $now = self::now();
            if ($object === null) {
                $object = $this->objects->create($actor, $blueprint->name, $prepared['values'], $publishable && $importer->publish && $prepared['publish_at'] === null, $relations);
                if ($publishable && $prepared['publish_at'] !== null) {
                    $this->objects->publish($actor, $object, $prepared['publish_at']);
                }
                $this->db->execute(
                    'DELETE FROM {import_items} WHERE importer = :i AND item_key = :k',
                    ['i' => $importer->name, 'k' => $prepared['key']],
                );
                $this->db->insert(CoreSchema::IMPORT_ITEMS, [
                    'importer' => $importer->name, 'item_key' => $prepared['key'], 'object_id' => (int) $object->id(),
                    'hash' => $prepared['hash'], 'created_run' => $runId, 'last_run' => $runId, 'updated_at' => $now,
                ]);

                return 'created';
            }
            $this->objects->update($actor, $object, $prepared['values'], $relations);
            if ($publishable && $prepared['publish_at'] !== null && $object->as(Publishable::class)->publishedAt()?->getTimestamp() !== $prepared['publish_at']->getTimestamp()) {
                $this->objects->publish($actor, $object, $prepared['publish_at']);
            }
            $this->db->update(CoreSchema::IMPORT_ITEMS, ['hash' => $prepared['hash'], 'last_run' => $runId, 'updated_at' => $now], ['importer' => $importer->name, 'item_key' => $prepared['key']]);

            return 'updated';
        });
    }

    /**
     * An item's values checked as saving checks them, without saving (a trial): saved in
     * a transaction that is rolled back.
     *
     * @param array{values: array<string, mixed>, relations: array<string, list<int>>} $prepared
     * @throws ValidationException
     */
    private function validate(Blueprint $blueprint, array $prepared, ?CampanellaObject $object): void
    {
        try {
            $this->db->transactional(function () use ($blueprint, $prepared, $object): void {
                $object ??= $this->repository->create($blueprint->name, []);
                $object->fill($prepared['values']);
                foreach ($prepared['relations'] as $name => $ids) {
                    $object->setRelated($name, $ids);
                }
                $this->repository->save($object);

                throw new TrialRollback();
            });
        } catch (TrialRollback) {
            // Checked; nothing saved.
        }
    }

    /** A relation target made for an item (`missing_target: create`), once per run and title. */
    private function make(ImporterDefinition $importer, string $blueprint, string $title, Actor $actor): int
    {
        $key = $blueprint . "\0" . mb_strtolower($title, 'UTF-8');
        if (isset($this->made[$key])) {
            return $this->made[$key];
        }
        $target = $this->blueprints->get($blueprint);
        $object = $this->objects->create($actor, $blueprint, ['title' => mb_substr($title, 0, 255, 'UTF-8')], isset($target->capabilities['publishable']) && $importer->publish);

        return $this->made[$key] = (int) $object->id();
    }

    /**
     * The objects of a relation's target Blueprints named by the values: by machine name,
     * path or title, in this order (a title only if one object of them has it).
     *
     * @param list<mixed> $values
     * @return array{0: list<int>, 1: list<string>} The IDs found, and the values not found
     */
    private function targets(Relation $relation, array $values): array
    {
        $ids = [];
        $missing = [];
        foreach ($values as $value) {
            $text = trim(self::text($value));
            if ($text === '') {
                continue;
            }
            $id = null;
            foreach (['machine_name' => 'cap_keyed', 'path' => 'cap_routable', 'title' => 'cap_titled'] as $column => $table) {
                if (mb_strlen($text, 'UTF-8') > 255 || !(new SchemaReader($this->db))->tableExists($table)) {
                    continue;
                }
                $in = [];
                $params = ['v' => $text];
                foreach ($relation->targetBlueprints as $i => $name) {
                    $in[] = ':b' . $i;
                    $params['b' . $i] = $name;
                }
                $found = $this->db->fetchColumn(
                    sprintf('SELECT o.id FROM {objects} o JOIN {%s} c ON c.object_id = o.id WHERE o.blueprint IN (%s) AND c.%s = :v ORDER BY o.id LIMIT 2', $table, implode(', ', $in), $column),
                    $params,
                );
                if (count($found) === 1) {
                    $id = (int) $found[0];
                    break;
                }
            }
            $made = $this->made[$relation->targetBlueprints[0] . "\0" . mb_strtolower($text, 'UTF-8')] ?? null;
            if ($id === null && $made !== null) {
                $id = $made;
            }
            if ($id === null) {
                $missing[] = $text;
            } elseif (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return [$ids, $missing];
    }

    /**
     * Makes the objects of an importer whose items were not in the run drafts
     * (`missing_items: draft`); how many were.
     */
    private function missing(ImporterDefinition $importer, int $runId, Actor $actor): int
    {
        if ($importer->missingItems !== 'draft') {
            return 0;
        }
        $ids = array_map('intval', $this->db->fetchColumn(
            'SELECT object_id FROM {import_items} WHERE importer = :i AND last_run <> :r',
            ['i' => $importer->name, 'r' => $runId],
        ));
        $count = 0;
        foreach ($this->repository->loadMany($ids) as $object) {
            if ($object->has(Publishable::class) && $object->as(Publishable::class)->status()->value !== 'draft') {
                try {
                    $this->objects->unpublish($actor, $object);
                    $count++;
                } catch (AccessDeniedException) {
                    // The importer's user may not: it stays.
                }
            }
        }

        return $count;
    }

    /** @return array<string, mixed>|null */
    private function item(string $importer, string $key): ?array
    {
        return $this->ready() ? $this->db->fetchOne('SELECT * FROM {import_items} WHERE importer = :i AND item_key = :k', ['i' => $importer, 'k' => $key]) : null;
    }

    private function runFile(int $runId): ?string
    {
        $file = $this->db->fetchValue('SELECT file FROM {import_runs} WHERE id = :id', ['id' => $runId]);

        return is_string($file) && $file !== '' ? $this->directory . '/' . basename($file) : null;
    }

    /** @param array<string, mixed> $values */
    private function end(int $runId, string $status, array $values): void
    {
        $file = $this->runFile($runId);
        if ($file !== null) {
            @unlink($file);
        }
        $this->db->update(CoreSchema::IMPORT_RUNS, ['status' => $status, 'file' => null, 'finished_at' => self::now()] + $values, ['id' => $runId]);
    }

    private static function errorsJson(ImportRun $run, int $row, string $key, string $field, Message $message): string
    {
        $errors = array_map(static fn (array $e): array => ['row' => $e['row'], 'key' => $e['key'], 'field' => $e['field'], 'message' => $e['message']->key, 'params' => $e['message']->params], $run->errors);
        $errors[] = ['row' => $row, 'key' => $key, 'field' => $field, 'message' => $message->key, 'params' => $message->params];

        return json_encode($errors, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function ready(): bool
    {
        return (new SchemaReader($this->db))->tableExists(CoreSchema::IMPORT_RUNS);
    }

    /** A value as the hash sees it. */
    private static function normal(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CalendarDate => $value->format('Y-m-d'),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_array($value) => array_map(self::normal(...), $value),
            default => $value,
        };
    }

    /** A value as a trial shows it (a moment in the site's time zone). */
    private function show(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof CalendarDate => $value->format('Y-m-d'),
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone($this->timezone)->format('Y-m-d H:i'),
            is_bool($value) => $value ? '✔' : '✘',
            is_array($value) => implode(', ', array_map($this->show(...), $value)),
            default => mb_strimwidth(trim(strip_tags((string) (is_scalar($value) ? $value : ''))), 0, 120, '…', 'UTF-8'),
        };
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT);
    }
}

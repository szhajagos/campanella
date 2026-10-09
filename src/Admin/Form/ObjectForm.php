<?php

declare(strict_types=1);

namespace Campanella\Admin\Form;

use Campanella\Tree\TreeBuilder;
use Campanella\Capability\Weighted;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Link;
use Campanella\Access\Actor;
use Campanella\Capability\TextFormat;
use Campanella\Capability\Textual;
use Campanella\Html\HtmlSanitizer;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\Relation;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The editing form of an object, generated from its field and relation
 * definitions: build() turns them into FormFields for the templates, read()
 * turns the submitted form back into values and relation targets.
 *
 * Posted names: f[<field>] (f[<field>][] for a multi-valued field) and
 * r[<relation>] (r[<relation>][] for a Many relation).
 */
final class ObjectForm
{
    /**
     * Fields that are not edited in the form: the publication status and time are
     * changed by the publish/unpublish actions, the text format by converting a text
     * to HTML, a file's data (MediaFile) by uploading it.
     */
    public const array MANAGED_FIELDS = [
        'status', 'published_at', 'format',
        'file_path', 'mime_type', 'file_size', 'width', 'height', 'file_hash', 'variants',
    ];

    /** The most targets offered for a relation. */
    public const int MAX_OPTIONS = 500;

    /** The value format of <input type="datetime-local" step="1"> (with seconds, so they survive a save). */
    private const string LOCAL_DATETIME = 'Y-m-d\TH:i:s';

    /** The range of the INT column of an Integer field. */
    private const int INT_MIN = -2147483648;
    private const int INT_MAX = 2147483647;

    private readonly DateTimeZone $timezone;

    /** The editor (toolbar) profiles of HTML text fields; the first one is the default. */
    public const array EDITOR_PROFILES = ['full', 'basic'];

    private readonly HtmlSanitizer $html;

    /**
     * @param HtmlSanitizer|null $html Its allowlist is given to the HTML editor, so the editor
     *        offers and keeps what the filter keeps (since 0.0.5); null: the built-in allowlist
     */
    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Translator $translator,
        string $timezone = 'UTC',
        ?HtmlSanitizer $html = null,
    ) {
        $this->timezone = new DateTimeZone($timezone);
        $this->html = $html ?? new HtmlSanitizer();
    }

    /**
     * @param array{f?: array<string, mixed>, r?: array<string, mixed>}|null $input Submitted values to show
     *        again (after a failed save); null: the object's own values.
     * @param array<string, Message> $errors field or relation name => message
     * @param list<string> $order Field and relation names in the order they should come first
     *        (Blueprint 'form_order'); the rest follow in their natural order.
     * @param array<string, string> $editors The editor profile of HTML text fields (Blueprint
     *        'editor'); an unknown or missing one is the first of EDITOR_PROFILES.
     * @param string|null $treeScope The Blueprint's 'tree_scope' (e.g. `menu`): the parent is
     *        offered from the same scope (since 0.0.7)
     * @return list<FormField>
     */
    public function build(
        CampanellaObject $object,
        Actor $actor,
        ?array $input = null,
        array $errors = [],
        array $order = [],
        array $editors = [],
        ?string $treeScope = null,
    ): array {
        $form = [];
        foreach ($this->editableFields($object) as $name => $field) {
            $value = $input === null
                ? $this->present($field, $object->get($name))
                : $this->presentInput($field, $input['f'][$name] ?? null);
            $textual = $name === 'body' && $object->has(Textual::class);
            $htmlBody = $textual && $object->as(Textual::class)->format() === TextFormat::Html;
            $profile = in_array($editors[$name] ?? null, self::EDITOR_PROFILES, true) ? (string) $editors[$name] : self::EDITOR_PROFILES[0];

            $form[] = new FormField(
                name: $name,
                kind: 'field',
                widget: $htmlBody ? 'html' : $this->widget($field),
                label: $field->label !== '' ? $field->label : $name,
                required: $field->required && $name !== 'path',
                multiple: $field->isMultiple(),
                max: $field->isMultiple() && !$field->isUnlimited() ? $field->cardinality : null,
                value: $value,
                error: isset($errors[$name]) ? $errors[$name]->translate($this->translator) : null,
                help: match (true) {
                    $htmlBody => 'admin.form.html_help',
                    $name === 'path' => 'admin.form.path_help',
                    $name === 'url' && $object->has(Link::class) => 'admin.form.url_help',
                    $name === 'machine_name' => 'admin.form.machine_name_help',
                    default => null,
                },
                attributes: match (true) {
                    // The editor gets its toolbar profile and the filter's allowlist.
                    $htmlBody => [
                        'rows' => 18,
                        'editor' => $profile,
                        'allow_tags' => $this->editorAllowlist(),
                        'external_images' => $this->html->allowsExternalImages() ? 1 : 0,
                    ],
                    $field->type === FieldType::String => ['maxlength' => $field->length],
                    // The main text (Textual body) gets a tall box, other texts a shorter one; a saved
                    // plain body can be converted to a formatted one (AdminController's convert-html).
                    $field->type === FieldType::Text => ['rows' => $name === 'body' ? 14 : 4] + ($textual && !$object->isNew() ? ['convertible' => 1] : []),
                    default => [],
                },
            );
        }

        foreach ($object->relations() as $name => $relation) {
            $selected = $input === null
                ? array_map(strval(...), $object->relatedIds($name))
                : array_map(strval(...), array_values(array_filter((array) ($input['r'][$name] ?? []), is_scalar(...))));

            $form[] = new FormField(
                name: $name,
                kind: 'relation',
                widget: $relation->isMany() ? 'checkboxes' : 'select',
                label: $relation->label !== '' ? $relation->label : $name,
                required: $relation->required,
                multiple: $relation->isMany(),
                max: $relation->max,
                value: $relation->isMany() ? $selected : ($selected[0] ?? ''),
                options: $this->options($relation, $object, $actor, $treeScope),
                error: isset($errors[$name]) ? $errors[$name]->translate($this->translator) : null,
            );
        }

        if ($order !== []) {
            $position = array_flip($order);
            $natural = array_flip(array_map(static fn (FormField $f): string => $f->name, $form));
            usort($form, static fn (FormField $a, FormField $b): int
                => [$position[$a->name] ?? PHP_INT_MAX, $natural[$a->name]] <=> [$position[$b->name] ?? PHP_INT_MAX, $natural[$b->name]]);
        }

        return $form;
    }

    /**
     * The submitted form as values and relation targets. Values that cannot be
     * read (e.g. "abc" for a number) are reported as errors instead.
     *
     * Relations: only targets that the form offered can be added or removed.
     * Current targets that were not offered (the actor cannot see them, or they
     * did not fit into the option list) are kept; posted IDs that were not
     * offered are ignored.
     *
     * @param array<string, mixed> $post The request's POST data
     * @return array{values: array<string, mixed>, relations: array<string, list<int>>, errors: array<string, Message>}
     */
    public function read(CampanellaObject $object, array $post, Actor $actor): array
    {
        $fields = is_array($post['f'] ?? null) ? $post['f'] : [];
        $relations = is_array($post['r'] ?? null) ? $post['r'] : [];

        $values = [];
        $errors = [];
        foreach ($this->editableFields($object) as $name => $field) {
            $raw = $fields[$name] ?? null;
            try {
                $values[$name] = $field->isMultiple()
                    ? array_map(fn (mixed $item): mixed => $this->parse($field, $item), is_array($raw) ? array_values($raw) : [])
                    : $this->parse($field, $raw);
            } catch (\UnexpectedValueException $e) {
                $errors[$name] = new Message($e->getMessage());
            }
        }

        $targets = [];
        foreach ($object->relations() as $name => $relation) {
            $offered = array_map(intval(...), array_column($this->options($relation, $object, $actor), 'value'));
            $current = $object->relatedIds($name);
            $kept = array_values(array_diff($current, $offered));

            $posted = [];
            foreach ((array) ($relations[$name] ?? []) as $id) {
                if (is_scalar($id) && ctype_digit((string) $id) && in_array((int) $id, $offered, true)
                    && !in_array((int) $id, $posted, true)) {
                    $posted[] = (int) $id;
                }
            }
            if ($relation->isMany()) {
                // Keep the existing order of the targets that stay, then the new ones.
                $chosen = array_values(array_filter($current, static fn (int $id): bool => in_array($id, $posted, true) || in_array($id, $kept, true)));
                $targets[$name] = array_values(array_unique([...$chosen, ...$posted]));
            } else {
                $targets[$name] = $posted !== [] ? [$posted[0]] : $kept;
            }
        }

        return ['values' => $values, 'relations' => $targets, 'errors' => $errors];
    }

    /** @return array<string, Field> */
    private function editableFields(CampanellaObject $object): array
    {
        return array_filter(
            $object->fields(),
            static fn (Field $field): bool => !$field->hidden && !in_array($field->name, self::MANAGED_FIELDS, true),
        );
    }

    /**
     * The allowlist as the editor (Jodit's cleanHTML.allowTags) expects it, as JSON:
     * element => true, or element => {attribute: true, …}. The elements the filter
     * unwraps (span, font…) are listed too, without attributes: the editor would
     * otherwise remove them together with their text, the filter keeps the text.
     */
    private function editorAllowlist(): string
    {
        $tags = [];
        foreach ($this->html->allowedElements() as $element => $attributes) {
            $tags[$element] = $attributes === [] ? true : array_fill_keys($attributes, true);
        }
        $tags += array_fill_keys(HtmlSanitizer::UNWRAPPED, true);

        return json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function widget(Field $field): string
    {
        return match ($field->type) {
            FieldType::String => 'string',
            FieldType::Text => 'text',
            FieldType::Integer => 'integer',
            FieldType::Boolean => 'boolean',
            FieldType::DateTime => 'datetime',
        };
    }

    /** @return string|list<string> The object's value as form text. */
    private function present(Field $field, mixed $value): string|array
    {
        if ($field->isMultiple()) {
            return array_values(array_map(fn (mixed $item): string => $this->presentOne($field, $item), (array) $value));
        }

        return $this->presentOne($field, $value);
    }

    private function presentOne(Field $field, mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
                ->setTimezone($this->timezone)->format(self::LOCAL_DATETIME),
            is_bool($value) => $value ? '1' : '',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /** @return string|list<string> The submitted value, unchanged, for showing it again. */
    private function presentInput(Field $field, mixed $raw): string|array
    {
        if ($field->isMultiple()) {
            return array_values(array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                is_array($raw) ? $raw : [],
            ));
        }

        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * One submitted value as a PHP value of the field's type.
     *
     * @throws \UnexpectedValueException with a message key if the value cannot be read
     */
    private function parse(Field $field, mixed $raw): mixed
    {
        if ($field->type === FieldType::Boolean) {
            return is_scalar($raw) && (string) $raw === '1';
        }
        $text = is_scalar($raw) ? (string) $raw : '';

        return match ($field->type) {
            FieldType::String => trim($text),
            FieldType::Text => str_replace("\r\n", "\n", $text),
            FieldType::Integer => match (true) {
                trim($text) === '' => null,
                preg_match('/^-?\d{1,10}$/', trim($text)) === 1
                    && (int) trim($text) >= self::INT_MIN && (int) trim($text) <= self::INT_MAX => (int) trim($text),
                default => throw new \UnexpectedValueException('validation.invalid_number'),
            },
            FieldType::DateTime => $this->parseDate(trim($text)),
        };
    }

    /**
     * A date and time typed in the site's time zone (the value of a datetime-local
     * input, or a similar form such as `2026-10-02 14:30`), as a UTC time.
     * Empty text: null. Since 0.0.4.
     *
     * @throws \UnexpectedValueException with the key 'validation.invalid_date' if it cannot be read
     */
    public function parseDateTime(string $text): ?DateTimeImmutable
    {
        return $this->parseDate(trim($text));
    }

    /**
     * A time in the site's time zone, by default in the datetime-local input format
     * (`Y-m-d\TH:i:s`); null: empty text. Since 0.0.4.
     */
    public function localDateTime(?DateTimeInterface $time, string $format = self::LOCAL_DATETIME): string
    {
        return $time === null ? '' : DateTimeImmutable::createFromInterface($time)->setTimezone($this->timezone)->format($format);
    }

    /** The site's time zone, in which dates are typed and shown (the `timezone` setting). */
    public function timezone(): string
    {
        return $this->timezone->getName();
    }

    private function parseDate(string $text): ?DateTimeImmutable
    {
        if ($text === '') {
            return null;
        }
        foreach ([self::LOCAL_DATETIME, 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $text, $this->timezone);
            if ($date !== false && $date->format($format) === $text) {
                return $date->setTimezone(new DateTimeZone('UTC'));
            }
        }

        throw new \UnexpectedValueException('validation.invalid_date');
    }

    /**
     * The possible targets of a relation that the actor may see: by the target
     * Blueprints and capabilities, ordered by title, without the object itself.
     *
     * @return list<array{value: string, label: string}>
     */
    private function options(Relation $relation, CampanellaObject $object, Actor $actor, ?string $treeScope = null): array
    {
        if ($relation->name === 'parent' && $object->has(Hierarchical::class)) {
            return $this->treeOptions($object, $actor, $treeScope);
        }
        $query = Query::objects();
        if ($relation->targetBlueprints !== []) {
            $query = $query->blueprint(...$relation->targetBlueprints);
        }
        if ($relation->targetCapabilities !== []) {
            $query = $query->having(...$relation->targetCapabilities);
        }
        $targets = $this->queries->execute($query->orderBy('title')->limit(self::MAX_OPTIONS), $actor)->items;

        // The current targets are always offered (if the actor may see them), even beyond the limit.
        $listed = array_map(static fn (CampanellaObject $o): int => (int) $o->id(), $targets);
        $missing = array_values(array_diff($object->isNew() ? [] : $object->relatedIds($relation->name), $listed));
        if ($missing !== []) {
            $extra = $this->queries->execute($query->where('id', 'IN', $missing), $actor)->items;
            array_push($targets, ...$extra);
        }

        $options = [];
        foreach ($targets as $target) {
            if ($target->id() === $object->id()) {
                continue;
            }
            $title = $target->hasField('title') ? (string) $target->get('title') : '';
            $options[] = ['value' => (string) $target->id(), 'label' => $title !== '' ? $title : '#' . $target->id()];
        }

        return $options;
    }

    /**
     * The possible parents of a tree node: the objects of its Blueprint, in tree
     * order, indented, without the object itself and its descendants (they would
     * make a circle). With a scope (e.g. menu): those of the object's own scope; if
     * it has none yet, all, each prefixed with its scope's title.
     *
     * @return list<array{value: string, label: string}>
     */
    private function treeOptions(CampanellaObject $object, Actor $actor, ?string $treeScope = null): array
    {
        $query = Query::objects()->blueprint($object->blueprint());
        $scopeTarget = $treeScope === null ? null : ($object->relatedIds($treeScope)[0] ?? null);
        if ($treeScope !== null && $scopeTarget !== null) {
            $query = $query->whereRelated($treeScope, $scopeTarget);
        }
        $query = $object->has(Weighted::class) ? $query->orderBy('weight')->orderBy('id') : $query->orderBy('title');
        $all = $this->queries->execute($query->limit(self::MAX_OPTIONS), $actor)->items;
        $own = $object->isNew() ? '' : $object->as(Hierarchical::class)->path();

        // Without a scope chosen yet: each option says which scope (e.g. menu) it is in.
        $scopeTitles = [];
        if ($treeScope !== null && $scopeTarget === null) {
            $ids = [];
            foreach ($all as $item) {
                $ids[] = $item->relatedIds($treeScope)[0] ?? 0;
            }
            $ids = array_values(array_filter(array_unique($ids)));
            if ($ids !== []) {
                foreach ($this->queries->execute(Query::objects()->where('id', 'IN', $ids), $actor) as $scope) {
                    $title = $scope->hasField('title') ? (string) $scope->get('title') : '';
                    $scopeTitles[(int) $scope->id()] = ($title !== '' ? $title : '#' . $scope->id()) . ' › ';
                }
            }
        }

        $options = [];
        foreach (TreeBuilder::flatten(TreeBuilder::build($all)) as $node) {
            $target = $node->object;
            if ($target->id() === $object->id() || ($own !== '' && str_starts_with($target->as(Hierarchical::class)->path(), $own))) {
                continue;
            }
            $title = $target->hasField('title') ? (string) $target->get('title') : '';
            $prefix = $treeScope !== null ? ($scopeTitles[$target->relatedIds($treeScope)[0] ?? 0] ?? '') : '';
            $options[] = [
                'value' => (string) $target->id(),
                'label' => $prefix . str_repeat("\u{2014}\u{a0}", $node->level) . ($title !== '' ? $title : '#' . $target->id()),
            ];
        }

        return $options;
    }
}

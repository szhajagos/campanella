<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\I18n\Message;
use Campanella\Lists\ListDefinition;
use Campanella\Lists\ListRegistry;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\View\Presentation;
use Closure;
use DateTimeZone;

/**
 * Reads a source and shows it with a display (since 0.3.3).
 *
 * A source is written as a component stores it:
 *
 *     object:12       an object, by its ID
 *     list:esemenyek  a list made in the admin, by its machine name
 *     current         the page's object (an article on its own page)
 *     system:visitor  a system source, by its name
 *
 * and is always read with the visitor's rights (the Query engine and the policy, as every
 * page reads): what the visitor may not see (a draft, a file for members) is not shown,
 * and a display of it renders nothing.
 *
 *     $html = $renderer->show('system:visitor', 'login_button', [], $context);
 *     $html = $renderer->render('download', Subject::object($file), ['show_details' => false], $context);
 */
final class DisplayRenderer
{
    /** The most objects a display shows from a list. */
    public const int MAX_ITEMS = ListDefinition::MAX_PER_PAGE;

    private int $rendered = 0;

    /**
     * @param Closure(): Presentation $presentation Lazy, as Presentation builds on Twig
     */
    public function __construct(
        private readonly DisplayRegistry $displays,
        private readonly Closure $presentation,
        private readonly ?QueryEngine $queries = null,
        private readonly ?ListRegistry $lists = null,
        private readonly ?BlueprintRegistry $blueprints = null,
        private readonly ?RelationLoader $relations = null,
        private readonly DateTimeZone $timezone = new DateTimeZone('UTC'),
    ) {
    }

    public function registry(): DisplayRegistry
    {
        return $this->displays;
    }

    /**
     * A source's parts: `['object', '12']`, `['list', 'esemenyek']`, `['current', '']`,
     * `['system', 'visitor']`.
     *
     * @return array{0: string, 1: string}
     * @throws DisplayException if it is not written as a source
     */
    public static function parseSource(string $source): array
    {
        if ($source === 'current') {
            return ['current', ''];
        }
        if (preg_match('/^object:([1-9][0-9]{0,18})$/D', $source, $m) === 1) {
            return ['object', $m[1]];
        }
        if (preg_match('/^(list|system):([a-z][a-z0-9_]{0,62})$/D', $source, $m) === 1) {
            return [$m[1], $m[2]];
        }

        throw new DisplayException('Not a source: ' . json_encode($source));
    }

    /**
     * What a source shows here, for this visitor; null if nothing (a hidden or deleted
     * object, an unknown list, no page object).
     *
     * @param int|null $limit For a list: at most this many objects (else the list's own page size)
     * @throws DisplayException if it is not written as a source, or names an unknown system source
     */
    public function subject(string $source, DisplayContext $context, ?int $limit = null): ?Subject
    {
        [$kind, $name] = self::parseSource($source);

        return match ($kind) {
            'current' => $context->current === null ? null : Subject::object($context->current),
            'object' => $this->object((int) $name, $context),
            'list' => $this->list($name, $context, $limit),
            default => $this->displays->source($name)->subject($context),
        };
    }

    /**
     * A source shown with a display: '' if the source shows nothing here.
     *
     * @param array<string, mixed> $settings
     * @throws DisplayException for an unknown display or source, or one that cannot show the other
     */
    public function show(string $source, string $display, array $settings, DisplayContext $context): string
    {
        $definition = $this->displays->definition($display);
        $settings = $this->settings($display, $settings);
        $limit = isset($definition->settings['count']) ? (int) $settings['count'] : null;
        $subject = $this->subject($source, $context, $limit);

        return $subject === null ? '' : $this->render($display, $subject, $settings, $context);
    }

    /**
     * A subject shown with a display.
     *
     * @param array<string, mixed> $settings Invalid or unknown ones are replaced by the defaults
     * @throws DisplayException for an unknown display, or one that cannot show the subject
     */
    public function render(string $display, Subject $subject, array $settings, DisplayContext $context): string
    {
        if (!$this->displays->accepts($display, $subject)) {
            $this->displays->definition($display); // an unknown display says so
            throw new DisplayException(sprintf('The display %s cannot show %s.', $display, self::describe($subject)));
        }
        $instance = $this->displays->display($display);
        $settings = $this->settings($display, $settings);
        $items = $subject->items;
        if ($subject->isList() && isset($settings['count'])) {
            $items = array_slice($items, 0, max(1, (int) $settings['count']));
        }
        $variables = $instance->variables($subject, $settings, $context);
        if ($variables === null) {
            return '';
        }

        return ($this->presentation)()->renderFirst($instance->templates($display, $subject), $variables + [
            'subject' => $subject,
            'settings' => $settings,
            'display' => $display,
            'id' => 'display-' . ++$this->rendered,
            'object' => $subject->object,
            'items' => $items,
            'list' => $subject->list,
            'page_path' => $context->path,
        ]);
    }

    /**
     * The settings a display is shown with: the given ones cast to their fields' types,
     * the missing, empty or invalid ones replaced by their defaults, unknown ones left out.
     *
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     * @throws DisplayException for an unknown display
     */
    public function settings(string $display, array $given): array
    {
        $definition = $this->displays->definition($display);
        $settings = [];
        foreach ($definition->settings as $name => $field) {
            $value = self::cast($field, $given[$name] ?? null);
            $settings[$name] = $value === null || ($value === false && $field->type !== FieldType::Boolean) || $field->invalid($value) !== null || $field->isTooLong($value) || ($field->required && $field->isEmpty($value))
                ? self::cast($field, $field->default)
                : $value;
        }
        foreach ($this->displays->display($display)->validate($settings) as $name => $_) {
            $settings[$name] = self::cast($definition->settings[$name], $definition->settings[$name]->default);
        }

        return $settings;
    }

    /**
     * Checks settings as given in a form (for a component, 0.3.3 part 2): the cast ones,
     * and their problems.
     *
     * @param array<string, mixed> $given
     * @return array{settings: array<string, mixed>, errors: array<string, Message>}
     * @throws DisplayException for an unknown display
     */
    public function check(string $display, array $given): array
    {
        $definition = $this->displays->definition($display);
        $settings = [];
        $errors = [];
        foreach ($definition->settings as $name => $field) {
            $value = self::cast($field, $given[$name] ?? null);
            if ($value === null && !array_key_exists($name, $given)) {
                $value = self::cast($field, $field->default);
            }
            if ($field->required && $field->isEmpty($value)) {
                $errors[$name] = new Message('validation.required');
            } elseif ($value === false && $field->type !== FieldType::Boolean) {
                $errors[$name] = new Message($field->type === FieldType::Integer ? 'validation.invalid_number' : 'validation.invalid_choice');
            } elseif (($problem = $field->invalid($value)) !== null) {
                $errors[$name] = $problem;
            } elseif ($field->isTooLong($value)) {
                $errors[$name] = new Message('validation.value_too_long', ['max' => $field->length]);
            }
            $settings[$name] = $value;
        }
        if ($errors === []) {
            $errors = $this->displays->display($display)->validate($settings);
        }

        return ['settings' => $settings, 'errors' => $errors];
    }

    /** A value of a setting, or null for none; false for one that is not of its type (not a Boolean). */
    private static function cast(Field $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return $field->cast($value);
        } catch (\InvalidArgumentException|\TypeError) {
            return $field->type === FieldType::Boolean ? null : false;
        }
    }

    private function object(int $id, DisplayContext $context): ?Subject
    {
        if ($this->queries === null) {
            return null;
        }
        $result = $this->queries->execute(Query::objects()->where('id', '=', $id), $context->actor);
        $this->relations?->resolve($result, $context->actor);
        $object = $result->first();

        return $object === null ? null : Subject::object($object);
    }

    private function list(string $name, DisplayContext $context, ?int $limit): ?Subject
    {
        $list = $this->lists?->find($name);
        if ($list === null || $this->queries === null || $this->blueprints === null) {
            return null;
        }
        $limit = max(1, min(self::MAX_ITEMS, $limit ?? $list->perPage));
        $result = $this->queries->execute($list->query($this->blueprints, $this->timezone)->limit($limit), $context->actor);
        $this->relations?->resolve($result, $context->actor);

        return Subject::list($result, $list);
    }

    private static function describe(Subject $subject): string
    {
        return match (true) {
            $subject->isList() => 'a list',
            $subject->isSystem() => 'the system source ' . $subject->source,
            default => 'an object of the Blueprint ' . $subject->object?->blueprint(),
        };
    }
}

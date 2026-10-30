<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Display\DisplayException;
use Campanella\Display\DisplayRenderer;
use Campanella\I18n\Message;
use Campanella\Layout\Conditions;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;

/**
 * The object is a component (since 0.3.3): it shows a source with a display, with its
 * settings. The source as a component stores it (`object:12`, `list:esemenyek`,
 * `current`, `system:visitor`) and the display's name are queryable fields; the
 * settings are JSON in the data column, never queried.
 *
 * This capability checks only their form; whether the display exists and can show the
 * source, and whether the settings are valid, ComponentService checks on every save
 * (it knows the displays), and again when a component is shown.
 */
#[AsCapability('component', label: 'capability.component')]
final class Component extends Capability
{
    /** The most bytes of a component's settings, as JSON. */
    public const int MAX_SETTINGS_BYTES = 16384;

    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('component_source', FieldType::String, required: true, indexed: true, length: 100, label: 'field.component_source'),
            new Field('component_display', FieldType::String, required: true, indexed: true, length: 64, label: 'field.component_display'),
            new Field('component_settings', FieldType::Text, storage: FieldStorage::Data, label: 'field.component_settings'),
            // Since 0.3.4: the region it is placed in (none: only component() shows it), and where it shows.
            new Field('component_region', FieldType::String, indexed: true, length: 64, label: 'field.component_region'),
            new Field('component_conditions', FieldType::Text, storage: FieldStorage::Data, label: 'field.component_conditions'),
            // Since 0.3.5: the page it belongs to (an object with Composed), or none: the whole site's.
            new Field('component_page', FieldType::Integer, indexed: true, label: 'field.component_page'),
        ];
    }

    public function source(): string
    {
        return (string) $this->object->get('component_source');
    }

    public function display(): string
    {
        return (string) $this->object->get('component_display');
    }

    /** @return array<string, mixed> The settings as stored (not checked again: the renderer does). */
    public function settings(): array
    {
        $settings = json_decode((string) $this->object->get('component_settings'), true);

        return is_array($settings) ? $settings : [];
    }

    /**
     * What it shows, how.
     *
     * @param array<string, mixed> $settings
     */
    public function configure(string $source, string $display, array $settings = []): void
    {
        $this->object->set('component_source', $source);
        $this->object->set('component_display', $display);
        $this->object->set('component_settings', json_encode((object) $settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** The region it is placed in, or null (since 0.3.4). */
    public function region(): ?string
    {
        $region = (string) $this->object->get('component_region');

        return $region === '' ? null : $region;
    }

    /**
     * The page it belongs to (since 0.3.5): the id of an object with Composed, whose own
     * content it is; null: the whole site's (shown where its conditions say).
     */
    public function page(): ?int
    {
        $page = $this->object->get('component_page');

        return is_int($page) && $page > 0 ? $page : (is_string($page) && ctype_digit($page) && (int) $page > 0 ? (int) $page : null);
    }

    /** Makes it a page's own (since 0.3.5), or the whole site's (null). */
    public function attachTo(?int $page): void
    {
        $this->object->set('component_page', $page !== null && $page > 0 ? $page : null);
    }

        /** Where it shows (since 0.3.4). */
    public function conditions(): Conditions
    {
        $stored = json_decode((string) $this->object->get('component_conditions'), true);

        return is_array($stored) ? Conditions::fromArray($stored) : Conditions::everywhere();
    }

    /** Places it in a region (null: none), where it shows (since 0.3.4). */
    public function place(?string $region, ?Conditions $conditions = null): void
    {
        $this->object->set('component_region', $region);
        $conditions ??= Conditions::everywhere();
        $this->object->set('component_conditions', $conditions->isEverywhere() ? null : json_encode($conditions->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    #[\Override]
    public function validate(): array
    {
        $errors = [];
        try {
            DisplayRenderer::parseSource($this->source());
        } catch (DisplayException) {
            $errors['component_source'] = new Message('validation.component_source');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $this->display()) !== 1) {
            $errors['component_display'] = new Message('validation.component_display');
        }
        $settings = (string) $this->object->get('component_settings');
        if ($settings !== '' && (strlen($settings) > self::MAX_SETTINGS_BYTES || !is_array(json_decode($settings, true)))) {
            $errors['component_settings'] = new Message('validation.component_settings');
        }

        $region = $this->region();
        if ($region !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $region) !== 1) {
            $errors['component_region'] = new Message('validation.component_region');
        }
        $conditions = (string) $this->object->get('component_conditions');
        if ($conditions !== '') {
            $stored = json_decode($conditions, true);
            if (strlen($conditions) > self::MAX_SETTINGS_BYTES || !is_array($stored)) {
                $errors['component_conditions'] = new Message('validation.component_conditions');
            } elseif (($problems = Conditions::fromArray($stored)->problems()) !== []) {
                $errors['component_conditions'] = reset($problems);
            }
        }

        return $errors;
    }
}

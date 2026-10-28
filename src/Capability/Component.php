<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Display\DisplayException;
use Campanella\Display\DisplayRenderer;
use Campanella\I18n\Message;
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

        return $errors;
    }
}

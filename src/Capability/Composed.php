<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;

/**
 * The object's page is assembled from components (since 0.3.5): it has components of its
 * own (those whose `component_page` is this object), shown in the regions of its layout
 * that are meant for a page's content (`"page": true`, e.g. the content and the sidebar),
 * and it may have a layout of its own. Its own text (Textual), if any, stays first in the
 * content region.
 *
 * The core's `page` Blueprint has it; a Blueprint made in the admin can have it too.
 */
#[AsCapability('composed', requires: [Routable::class], label: 'capability.composed')]
final class Composed extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            // The layout's name, or none: its Blueprint's, or the site's. Stored, never queried.
            new Field('page_layout', FieldType::String, storage: FieldStorage::Data, length: 64, label: 'field.page_layout'),
        ];
    }

    /** Its own layout's name, or null. */
    public function layout(): ?string
    {
        $layout = (string) $this->object->get('page_layout');

        return $layout === '' ? null : $layout;
    }

    /** Gives it a layout of its own (null: its Blueprint's, or the site's). */
    public function setLayout(?string $layout): void
    {
        $this->object->set('page_layout', $layout === '' ? null : $layout);
    }

    #[\Override]
    public function validate(): array
    {
        $layout = $this->layout();

        return $layout !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $layout) !== 1
            ? ['page_layout' => new Message('validation.page_layout')]
            : [];
    }
}

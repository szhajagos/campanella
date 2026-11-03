<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\I18n\Message;

/**
 * A module found in the modules folder, and where it stands (since 0.4.6).
 *
 * `status`:
 *   - `broken`: it cannot be used (`problems` says why: its module.json, its Campanella
 *     version, a module it needs);
 *   - `off`: it can be turned on;
 *   - `on`: it is on and is loaded;
 *   - `not_loaded`: it is on, but is not loaded now (`problems` says why: config/local.php
 *     turns it off, or a module it needs is not loaded); it comes back when that is gone.
 */
final readonly class ModuleState
{
    public const string BROKEN = 'broken';
    public const string OFF = 'off';
    public const string ON = 'on';
    public const string NOT_LOADED = 'not_loaded';

    /** @param list<Message> $problems */
    public function __construct(
        public string $name,
        public ?ModuleManifest $manifest,
        public string $status,
        public array $problems = [],
        public bool $enabled = false,
    ) {
    }

    public function isLoaded(): bool
    {
        return $this->status === self::ON;
    }
}

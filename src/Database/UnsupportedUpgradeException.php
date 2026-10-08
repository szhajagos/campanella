<?php

declare(strict_types=1);

namespace Campanella\Database;

use Campanella\Core\Version;
use Campanella\I18n\Message;

/**
 * The installation is too old to be upgraded by this version (older than
 * Version::MIN_UPGRADE_SCHEMA): it has to be upgraded through 0.0.7 first.
 * Since 0.1.0.
 */
final class UnsupportedUpgradeException extends \RuntimeException
{
    public readonly Message $reason;

    public function __construct(public readonly string $schema)
    {
        $this->reason = new Message('upgrade.too_old', ['version' => $schema, 'min' => '0.0.6', 'via' => '0.0.7']);
        parent::__construct(sprintf(
            'The database schema %s is older than %s: upgrade through Campanella 0.0.7 first.',
            $schema,
            Version::MIN_UPGRADE_SCHEMA,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\CapabilityDefinition;

/**
 * Elnevezett capability-csomag, a „típus” adatként.
 *
 * Nem PHP-osztály, hanem konfiguráció (config/blueprints.php), ezért
 * git-ben verziózható. A Blueprint adja meg, milyen capability-kkel
 * jön létre egy új objektum, és milyen egyedi mezői vannak.
 */
final readonly class Blueprint
{
    /**
     * @param array<string, CapabilityDefinition> $capabilities Feloldva, függőségekkel együtt.
     * @param array<string, Field> $fields Csak a Blueprint saját (egyedi) mezői.
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $capabilities,
        public array $fields,
    ) {
    }

    /** @return array<string, Field> A capability-mezők és az egyedi mezők együtt. */
    public function allFields(): array
    {
        $fields = [];
        foreach ($this->capabilities as $capability) {
            $fields += $capability->fields;
        }

        return $fields + $this->fields;
    }
}

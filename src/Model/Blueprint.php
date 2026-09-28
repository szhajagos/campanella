<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Query\Query;
use Campanella\Relation\Relation;
use Closure;

/**
 * Elnevezett capability-csomag, a „típus” adatként.
 *
 * Nem PHP-osztály, hanem konfiguráció (config/blueprints.php), ezért
 * git-ben verziózható. A Blueprint adja meg, milyen capability-kkel
 * jön létre egy új objektum, milyen egyedi mezői és kapcsolatai vannak,
 * és milyen listák tartoznak az objektum oldalához.
 */
final readonly class Blueprint
{
    /**
     * @param array<string, CapabilityDefinition> $capabilities Feloldva, függőségekkel együtt.
     * @param array<string, Field> $fields Csak a Blueprint saját (egyedi) mezői.
     * @param array<string, Relation> $relations Csak a Blueprint saját kapcsolatai.
     * @param array<string, array{label?: string, query: Closure(CampanellaObject): Query}> $lists
     *        Az objektum saját oldalán megjelenő listák: felirat és egy függvény, amely az
     *        objektumból Query-t készít (pl. egy kategória cikkei).
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $capabilities,
        public array $fields,
        public array $relations = [],
        public array $lists = [],
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

    /** @return array<string, Relation> A capability-kapcsolatok és a saját kapcsolatok együtt. */
    public function allRelations(): array
    {
        $relations = [];
        foreach ($this->capabilities as $capability) {
            $relations += $capability->relations;
        }

        return $relations + $this->relations;
    }
}

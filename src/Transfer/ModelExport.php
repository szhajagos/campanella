<?php

declare(strict_types=1);

namespace Campanella\Transfer;

use Campanella\Core\Version;
use Campanella\Lists\ListStore;
use Campanella\Model\BlueprintStore;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The content model's export (since 0.2.6): the Blueprints and lists made in the
 * admin, as stored, into a ModelFile; since 0.4.2 the colour schemes made there too. A list's relation condition names its
 * objects by reference (ObjectReferences), not by ID.
 */
final class ModelExport
{
    public function __construct(
        private readonly BlueprintStore $blueprints,
        private readonly ListStore $lists,
        private readonly ObjectReferences $references,
        private readonly ?\Campanella\Appearance\ColorSchemeStore $schemes = null,
    ) {
    }

    /**
     * @return array{blueprints: array<string, array<string, mixed>>, lists: array<string, array<string, mixed>>, color_schemes: array<string, array<string, mixed>>, campanella: string, exported_at: string}
     */
    public function model(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lists = [];
        foreach ($this->lists->load() as $name => $definition) {
            $conditions = [];
            foreach (is_array($definition['conditions'] ?? null) ? $definition['conditions'] : [] as $condition) {
                if (is_array($condition) && isset($condition['relation'])) {
                    $ids = array_values(array_filter(array_map(intval(...), (array) ($condition['targets'] ?? [])), static fn (int $id): bool => $id > 0));
                    $condition['targets'] = $this->references->describe($ids);
                }
                $conditions[] = $condition;
            }
            $definition['conditions'] = $conditions;
            $lists[$name] = $definition;
        }

        return [
            'blueprints' => $this->blueprints->load(),
            'lists' => $lists,
            // The colour schemes made in the admin (since 0.4.2), as a scheme's file has them.
            'color_schemes' => array_map(self::scheme(...), $this->schemes?->load() ?? []),
            'campanella' => Version::CAMPANELLA,
            'exported_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** The file's contents. */
    public function json(?DateTimeImmutable $now = null): string
    {
        return ModelFile::encode($this->model($now));
    }

    /**
     * A stored colour scheme in a file's order: version, label, light, dark, the colours as
     * ColorScheme::COLORS has them (MySQL's JSON column sorts the keys).
     *
     * @return array<string, mixed>
     */
    private static function scheme(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $ordered = array_intersect_key(['version' => null, 'label' => null, 'light' => null, 'dark' => null], $data);
        foreach (['light', 'dark'] as $variant) {
            if (isset($data[$variant]) && is_array($data[$variant])) {
                $data[$variant] = array_replace(array_intersect_key(\Campanella\Appearance\ColorScheme::COLORS, $data[$variant]), $data[$variant]);
            }
        }

        return array_replace($ordered, $data);
    }
}

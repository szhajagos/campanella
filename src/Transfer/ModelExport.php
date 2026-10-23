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
 * admin, as stored, into a ModelFile. A list's relation condition names its
 * objects by reference (ObjectReferences), not by ID.
 */
final class ModelExport
{
    public function __construct(
        private readonly BlueprintStore $blueprints,
        private readonly ListStore $lists,
        private readonly ObjectReferences $references,
    ) {
    }

    /**
     * @return array{blueprints: array<string, array<string, mixed>>, lists: array<string, array<string, mixed>>, campanella: string, exported_at: string}
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
            'campanella' => Version::CAMPANELLA,
            'exported_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** The file's contents. */
    public function json(?DateTimeImmutable $now = null): string
    {
        return ModelFile::encode($this->model($now));
    }
}

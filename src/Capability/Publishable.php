<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Az objektum publikálható.
 *
 * Egy objektum akkor látható nyilvánosan, ha státusza `published`, és a
 * `published_at` időpont már elmúlt. Így az időzített publikálás külön
 * mechanizmus nélkül működik: jövőbeli dátummal publikált tartalom
 * magától jelenik meg a megadott időben.
 */
#[AsCapability('publishable', label: 'Publikálható')]
final class Publishable extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field(
                'status',
                FieldType::String,
                required: true,
                default: PublishStatus::Draft->value,
                indexed: true,
                length: 16,
                label: 'Állapot',
            ),
            new Field('published_at', FieldType::DateTime, indexed: true, label: 'Publikálás ideje'),
        ];
    }

    #[\Override]
    public static function scopes(): array
    {
        return [
            'published' => static fn (Query $query): Query => $query
                ->where('status', '=', PublishStatus::Published)
                ->where('published_at', '<=', self::now()),
        ];
    }

    public function status(): PublishStatus
    {
        return PublishStatus::tryFrom((string) $this->object->get('status')) ?? PublishStatus::Draft;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        $value = $this->object->get('published_at');

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    public function isPublished(?DateTimeImmutable $now = null): bool
    {
        $at = $this->publishedAt();

        return $this->status() === PublishStatus::Published
            && $at !== null
            && $at <= ($now ?? self::now());
    }

    /**
     * @param DateTimeImmutable|null $at Mikortól legyen látható. Alapértelmezés:
     *        a korábbi publikálási idő, ennek hiányában most.
     */
    public function publish(?DateTimeImmutable $at = null): void
    {
        $this->object->set('status', PublishStatus::Published);
        $this->object->set('published_at', $at ?? $this->publishedAt() ?? self::now());
    }

    public function unpublish(): void
    {
        $this->object->set('status', PublishStatus::Draft);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

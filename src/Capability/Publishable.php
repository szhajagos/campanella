<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The object can be published.
 *
 * An object is publicly visible if its status is `published` and the
 * `published_at` time has passed. This way scheduled publishing works
 * without a separate mechanism: content published with a future date
 * appears by itself at the given time.
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
     * @param DateTimeImmutable|null $at When it becomes visible. Default:
     *        the earlier publication time, or now if there is none.
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

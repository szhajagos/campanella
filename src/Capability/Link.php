<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Relation\Cardinality;
use Campanella\Relation\Relation;

/**
 * The object points somewhere (e.g. a menu item): either to an object of the
 * site (the `target` relation, so a changed path is followed) or to a URL.
 * Exactly one of the two. Since 0.0.7.
 *
 * Only safe URLs are accepted (isSafeUrl()): a path of the site (`/hirek`), a
 * fragment (`#contact`), `http://` and `https://` addresses, `mailto:` and
 * `tel:`. Never `javascript:`, `data:` or anything else a browser might run.
 */
#[AsCapability('link', label: 'capability.link')]
final class Link extends Capability
{
    public const int MAX_URL_LENGTH = 2048;

    #[\Override]
    public static function fields(): array
    {
        return [
            // Never filtered on: it lives in the data column.
            new Field('url', FieldType::String, storage: FieldStorage::Data, length: self::MAX_URL_LENGTH, label: 'field.url'),
        ];
    }

    #[\Override]
    public static function relations(): array
    {
        return [
            new Relation('target', Cardinality::One, targetCapabilities: [Routable::class], label: 'relation.target'),
        ];
    }

    public function url(): string
    {
        return (string) $this->object->get('url');
    }

    public function targetId(): ?int
    {
        return $this->object->relatedIds('target')[0] ?? null;
    }

    /**
     * Where the link leads, without the installation's base path: the target's path
     * (pass the loaded target), or the URL. Null if the target is not given (e.g. the
     * visitor may not see it) or there is nothing to link to.
     */
    public function href(?CampanellaObject $target = null): ?string
    {
        if ($this->targetId() !== null) {
            return $target !== null && $target->has(Routable::class) ? $target->as(Routable::class)->route() : null;
        }
        $url = $this->url();

        return $url !== '' && self::isSafeUrl($url) ? $url : null;
    }

    #[\Override]
    public function prepareForSave(): void
    {
        $this->object->set('url', trim($this->url()));
    }

    #[\Override]
    public function validate(): array
    {
        $url = $this->url();
        $target = $this->targetId() !== null;

        return match (true) {
            $url === '' && !$target => ['url' => new Message('link.missing')],
            $url !== '' && $target => ['url' => new Message('link.both')],
            $url !== '' && !self::isSafeUrl($url) => ['url' => new Message('link.invalid_url')],
            default => [],
        };
    }

    /**
     * Whether the URL may be used as a link: a site path (`/…`, not `//…`), a fragment
     * (`#…`), an `http(s)://` address with a host, `mailto:` or `tel:`. No whitespace,
     * control characters or backslashes anywhere.
     */
    public static function isSafeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        return match (true) {
            $url[0] === '#' => true,
            $url[0] === '/' => !str_starts_with($url, '//'),
            preg_match('#^https?://#i', $url) === 1 => (string) (parse_url($url, PHP_URL_HOST) ?? '') !== '',
            preg_match('/^mailto:[^@]+@[^@]+$/i', $url) === 1 => true,
            preg_match('/^tel:\+?[0-9().\-]+$/i', $url) === 1 => true,
            default => false,
        };
    }

    /** Whether the URL is a path of this site (or a fragment), so it gets the base path. */
    public static function isLocal(string $url): bool
    {
        return $url !== '' && ($url[0] === '#' || ($url[0] === '/' && !str_starts_with($url, '//')));
    }
}

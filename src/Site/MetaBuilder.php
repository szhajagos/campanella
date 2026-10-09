<?php

declare(strict_types=1);

namespace Campanella\Site;

use Campanella\Access\Actor;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\TextFormat;
use Campanella\Capability\Titled;
use Campanella\Media\MediaStorage;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use DateTimeImmutable;

/**
 * Makes a page's PageMeta (since 0.1.1).
 *
 * For an object's own page:
 *  - description: the object's `lead` field; else the beginning of its text (body);
 *    else the site's default description;
 *  - image: the first uploaded image in its text; else the site's share image;
 *  - type `article` for a Publishable object (with its publication and modification
 *    times), `website` otherwise.
 * For a list (e.g. the front page): the site's description and share image.
 *
 * The canonical URL is the page's own path on the site's address (a list's later
 * pages with their `?page=`). Images are looked up as the anonymous visitor.
 */
final class MetaBuilder
{
    /** The longest description taken from a text (characters; search engines show about this much). */
    public const int DESCRIPTION_LENGTH = 160;

    public function __construct(
        private readonly SiteSettings $site,
        private readonly QueryEngine $queries,
        private readonly MediaStorage $media,
        private readonly string $mediaUrl = '/media',
    ) {
    }

    /** For an object's own page. */
    public function forObject(CampanellaObject $object): PageMeta
    {
        $title = $object->has(Titled::class) ? $object->as(Titled::class)->title() : '';
        $description = self::leadOf($object);
        $html = '';
        if ($object->has(Textual::class)) {
            $textual = $object->as(Textual::class);
            $html = $textual->format() === TextFormat::Html ? $textual->body() : '';
            if ($description === '') {
                $description = self::excerpt($textual->format() === TextFormat::Html ? self::textOf($textual->body()) : $textual->body());
            }
        }
        $image = $html !== '' ? $this->imageInHtml($html) : null;

        $published = null;
        $modified = null;
        $type = 'website';
        if ($object->has(Publishable::class)) {
            $type = 'article';
            $published = $object->as(Publishable::class)->publishedAt();
            $modified = $object->updated();
        }
        $path = $object->has(Routable::class) ? (string) $object->get('path') : null;

        return $this->make(
            $title !== '' ? $title : $this->site->name(),
            $description,
            $path === null ? null : $this->site->absolute($path),
            $type,
            $image,
            $published,
            $modified !== null && $published !== null && $modified > $published ? $modified : null,
        );
    }

    /**
     * For a list or another page of the site (e.g. the front page), at its path; a page
     * number above 1 is part of the canonical URL.
     */
    public function forPath(string $path, string $title = '', int $page = 1): PageMeta
    {
        $canonical = $this->site->absolute($path);
        if ($canonical !== null && $page > 1) {
            $canonical .= '?page=' . $page;
        }

        return $this->make($title !== '' ? $title : $this->site->name(), '', $canonical);
    }

    /**
     * The first uploaded image of an HTML text (an image object of this site), if any.
     */
    public function imageInHtml(string $html): ?CampanellaObject
    {
        $prefix = preg_quote(rtrim($this->mediaUrl, '/') . '/', '#');
        if (preg_match_all('#<img\b[^>]*\bsrc\s*=\s*["\']?[^"\'\s>]*?' . $prefix . '(\d{4}/\d{2}/[0-9a-f]{24}\.(?:jpg|png|webp|gif))#i', $html, $matches) < 1) {
            return null;
        }
        foreach (array_slice(array_unique($matches[1]), 0, 5) as $path) {
            $image = $this->queries->first(
                Query::objects()->having(MediaFile::class)->where('file_path', '=', $path),
                Actor::anonymous(),
            );
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /**
     * A text shortened to at most $length characters, at a word boundary, with an
     * ellipsis if something was cut. Whitespace is collapsed.
     */
    public static function excerpt(string $text, int $length = self::DESCRIPTION_LENGTH): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return (string) preg_replace('/[\s\x{a0},;:.\-–]+$/u', '', $cut) . '…';
    }

    /** The visible text of an HTML fragment (block elements become spaces). */
    public static function textOf(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/td|/th|/blockquote|/figcaption)\b[^>]*>#i', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function make(
        string $title,
        string $description,
        ?string $canonical,
        string $type = 'website',
        ?CampanellaObject $image = null,
        ?DateTimeImmutable $published = null,
        ?DateTimeImmutable $modified = null,
    ): PageMeta {
        $site = $this->site->values();
        if ($description === '') {
            $description = self::excerpt((string) ($site['description'] ?? ''), SiteSettings::MAX_LENGTH['description']);
        }
        $image ??= $this->shareImage();
        $imageUrl = null;
        if ($image !== null) {
            $imageUrl = $this->site->absolute($this->media->url((string) $image->get('file_path')));
        }

        return new PageMeta(
            title: $title,
            siteName: $this->site->name(),
            description: $description,
            canonical: $canonical,
            type: $type,
            imageUrl: $imageUrl,
            imageWidth: $imageUrl !== null ? self::intOrNull($image?->get('width')) : null,
            imageHeight: $imageUrl !== null ? self::intOrNull($image?->get('height')) : null,
            imageAlt: $imageUrl !== null && $image !== null && $image->hasField('alt') ? (string) $image->get('alt') : '',
            publishedTime: $published?->format(DATE_ATOM),
            modifiedTime: $modified?->format(DATE_ATOM),
            robots: $this->site->indexing() ? null : 'noindex',
        );
    }

    private function shareImage(): ?CampanellaObject
    {
        $id = $this->site->shareImage();
        if ($id === null) {
            return null;
        }

        return $this->queries->first(Query::objects()->having(MediaFile::class)->where('id', '=', $id), Actor::anonymous());
    }

    private static function leadOf(CampanellaObject $object): string
    {
        if (!$object->hasField('lead')) {
            return '';
        }
        $lead = $object->get('lead');

        return is_string($lead) ? self::excerpt($lead, SiteSettings::MAX_LENGTH['description']) : '';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}

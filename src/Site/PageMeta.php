<?php

declare(strict_types=1);

namespace Campanella\Site;

/**
 * What a page tells search engines and social sites about itself (since 0.1.1):
 * the `<meta>` and `<link>` elements of the page's head, written by
 * templates/page/_meta.html.twig. Made by MetaBuilder.
 *
 * Every URL is absolute; one that needs the site's address is null while the
 * address is not set (then the element is left out).
 */
final readonly class PageMeta
{
    public function __construct(
        /** The page's title for sharing (og:title): its own, or the site's name. */
        public string $title,
        public string $siteName,
        /** The meta description (may be empty). */
        public string $description = '',
        /** The canonical URL (link rel=canonical, og:url). */
        public ?string $canonical = null,
        /** The Open Graph type: `website` or `article`. */
        public string $type = 'website',
        public ?string $imageUrl = null,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
        public string $imageAlt = '',
        /** ISO 8601 times of an article (article:published_time, article:modified_time). */
        public ?string $publishedTime = null,
        public ?string $modifiedTime = null,
        /** `noindex` while search engines are asked not to index the site, otherwise null. */
        public ?string $robots = null,
    ) {
    }
}

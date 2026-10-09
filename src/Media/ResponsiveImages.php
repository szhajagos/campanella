<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Access\Actor;
use Campanella\Capability\MediaFile;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

/**
 * Images in the size the visitor needs (since 0.1.2): the addresses of an image's
 * smaller copies, and `srcset` for the images of HTML texts.
 *
 * The texts are stored as they were written (`<img src="/media/2026/10/….jpg">`);
 * enrich() adds, when the page is rendered:
 *
 *   srcset="/media/…-320.jpg 320w, /media/…-640.jpg 640w, /media/….jpg 2560w"
 *   sizes="(max-width: 800px) 100vw, 800px"
 *   width, height (if missing: the browser keeps the place, the page does not jump)
 *   loading="lazy", decoding="async"
 *
 * Only images of this site get them (found by their file's path, one query for a
 * text); everything else is left as it is.
 */
final class ResponsiveImages
{
    /** The default `sizes`: the column of the default theme is at most 800 pixels wide. */
    public const string DEFAULT_SIZES = '(max-width: 800px) 100vw, 800px';

    /** A stored original's path inside an address (variants map back to their original). */
    private const string PATH = '#(\d{4}/\d{2}/[0-9a-f]{24})(?:-[1-9]\d{1,4})?\.(jpg|png|webp|gif)(?=[?\#"\'\s>]|$)#';

    public function __construct(
        private readonly QueryEngine $queries,
        private readonly MediaStorage $storage,
        private readonly string $sizes = self::DEFAULT_SIZES,
    ) {
    }

    /**
     * The address of an image (relative to the site root), or of its smallest copy at
     * least $width wide (the original if none is that wide).
     */
    public function url(CampanellaObject $image, ?int $width = null): string
    {
        $file = $image->as(MediaFile::class);
        if ($width !== null) {
            foreach ($file->variantWidths() as $variant) {
                if ($variant >= $width) {
                    return $this->storage->url(MediaFile::variantPath($file->path(), $variant));
                }
            }
        }

        return $this->storage->url($file->path());
    }

    /**
     * The `srcset` of an image: its copies and the original, with their widths; '' if it
     * has no copies (then `src` alone is enough). Addresses include the site's folder.
     */
    public function srcset(CampanellaObject $image, string $basePath = ''): string
    {
        $file = $image->as(MediaFile::class);
        $widths = $file->variantWidths();
        if ($widths === []) {
            return '';
        }
        $candidates = [];
        foreach ($widths as $width) {
            $candidates[] = $basePath . $this->storage->url(MediaFile::variantPath($file->path(), $width)) . ' ' . $width . 'w';
        }
        $width = $file->width();
        if ($width !== null) {
            $candidates[] = $basePath . $this->storage->url($file->path()) . ' ' . $width . 'w';
        }

        return implode(', ', $candidates);
    }

    /**
     * The HTML text with `srcset`, `sizes`, `width`, `height`, `loading` and `decoding` on
     * its images of this site (an attribute already there is kept). $basePath: the
     * installation's folder, for the addresses in `srcset`.
     */
    public function enrich(string $html, string $basePath = ''): string
    {
        if (stripos($html, '<img') === false || preg_match_all('#<img\b[^>]*>#i', $html, $tags) < 1) {
            return $html;
        }
        $paths = [];
        foreach ($tags[0] as $tag) {
            $src = self::attribute($tag, 'src');
            if ($src !== null && preg_match(self::PATH, $src, $m) === 1) {
                $paths[$m[1] . '.' . $m[2]] = true;
            }
        }
        if ($paths === []) {
            return $html;
        }
        $images = [];
        $query = Query::objects()->having(MediaFile::class)->where('file_path', 'IN', array_keys($paths))->limit(count($paths));
        foreach ($this->queries->execute($query, Actor::system()) as $image) {
            $images[(string) $image->get('file_path')] = $image;
        }

        return (string) preg_replace_callback('#<img\b[^>]*>#i', function (array $match) use ($images, $basePath): string {
            $tag = $match[0];
            $src = self::attribute($tag, 'src');
            if ($src === null || preg_match(self::PATH, $src, $m) !== 1 || !isset($images[$m[1] . '.' . $m[2]])) {
                return $tag;
            }
            $image = $images[$m[1] . '.' . $m[2]];
            $file = $image->as(MediaFile::class);
            $add = [];
            $srcset = $this->srcset($image, $basePath);
            if ($srcset !== '' && self::attribute($tag, 'srcset') === null) {
                $add['srcset'] = $srcset;
                $width = self::attribute($tag, 'width');
                $add['sizes'] = $width !== null && ctype_digit($width) && (int) $width > 0
                    ? sprintf('(max-width: %1$dpx) 100vw, %1$dpx', (int) $width)
                    : $this->sizes;
            }
            if (self::attribute($tag, 'width') === null && self::attribute($tag, 'height') === null
                && $file->width() !== null && $file->height() !== null) {
                $add['width'] = (string) $file->width();
                $add['height'] = (string) $file->height();
            }
            foreach (['loading' => 'lazy', 'decoding' => 'async'] as $name => $value) {
                if (self::attribute($tag, $name) === null) {
                    $add[$name] = $value;
                }
            }
            $attributes = '';
            foreach ($add as $name => $value) {
                $attributes .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
            }
            $selfClosing = str_ends_with($tag, '/>');

            return rtrim(substr($tag, 0, $selfClosing ? -2 : -1)) . $attributes . ($selfClosing ? ' />' : '>');
        }, $html);
    }

    /**
     * An attribute's value in a tag (decoded), or null if it has none. The tag's attributes
     * are read one after the other, so text inside another attribute's value (e.g. an
     * `alt` containing `src=`) is never taken for an attribute.
     */
    private static function attribute(string $tag, string $name): ?string
    {
        preg_match_all('#\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', substr($tag, 4), $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (strtolower($m[1]) === strtolower($name)) {
                $value = ($m[2] ?? '') !== '' ? $m[2] : ((($m[3] ?? '') !== '') ? $m[3] : ($m[4] ?? ''));

                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }
}

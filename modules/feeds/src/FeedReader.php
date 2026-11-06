<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\I18n\Message;
use Campanella\Import\ImportException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * An RSS (2.0, 0.9x, 1.0) or Atom (1.0) feed made into the JSON an importer reads, its items at
 * `items`, each with the same keys whatever the feed's kind:
 *
 *   id          the item's unique key (guid, Atom's id, else its link; a long one hashed)
 *   title       text
 *   link        its web address
 *   summary     HTML (RSS's description, Atom's summary)
 *   content     HTML (content:encoded, Atom's content), or the summary if none
 *   published   ISO 8601 (pubDate, dc:date, Atom's published or updated), or ''
 *   updated     ISO 8601, or ''
 *   author      a name or an e-mail address
 *   categories  a list of texts
 *   enclosure   the address of its attached file (an image, an audio file), or ''
 *
 * The XML is read safely: a file with a document type declaration (<!DOCTYPE>, where entities
 * would be defined) is refused, no external entity or file is ever loaded, nothing from the
 * network. The HTML is not cleaned here: the field it goes into does that when it is saved
 * (a formatted field's HTML sanitizer), as for every import.
 */
final class FeedReader
{
    public const int MAX_ITEMS = 1000;

    /** The items' keys, in order (an importer's sources). */
    public const array KEYS = ['id', 'title', 'link', 'summary', 'content', 'published', 'updated', 'author', 'categories', 'enclosure'];

    private const string ATOM = 'http://www.w3.org/2005/Atom';
    private const string RSS1 = 'http://purl.org/rss/1.0/';
    private const string CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const string DC = 'http://purl.org/dc/elements/1.1/';
    private const string RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /**
     * The feed as the importer's JSON.
     *
     * @throws ImportException if it is not a feed this class reads
     */
    public static function toJson(string $xml): string
    {
        return (string) json_encode(['items' => self::read($xml)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return list<array<string, string|list<string>>>
     * @throws ImportException
     */
    public static function read(string $xml): array
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if ($xml === '' || $xml[0] !== '<') {
            throw new ImportException(new Message('feeds.error.not_feed'));
        }
        if (preg_match('/<!DOCTYPE/i', $xml) === 1 || preg_match('/<!ENTITY/i', $xml) === 1) {
            throw new ImportException(new Message('feeds.error.doctype'));
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: nothing from the network; no LIBXML_NOENT or LIBXML_DTDLOAD: no entity is substituted, no DTD loaded.
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$loaded || $root === null || $document->doctype !== null) {
            throw new ImportException(new Message('feeds.error.xml'));
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('a', self::ATOM);
        $xpath->registerNamespace('r1', self::RSS1);
        $xpath->registerNamespace('content', self::CONTENT);
        $xpath->registerNamespace('dc', self::DC);
        $xpath->registerNamespace('rdf', self::RDF);

        $items = match (true) {
            $root->namespaceURI === self::ATOM && $root->localName === 'feed' => self::atom($xpath),
            $root->localName === 'rss' => self::rss($xpath, '/rss/channel/item'),
            $root->namespaceURI === self::RDF && $root->localName === 'RDF' => self::rss($xpath, '/rdf:RDF/r1:item'),
            default => throw new ImportException(new Message('feeds.error.not_feed')),
        };

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /** @return list<array<string, string|list<string>>> */
    private static function rss(DOMXPath $xpath, string $path): array
    {
        $items = [];
        foreach (self::elements($xpath, $path) as $item) {
            // RSS 1.0's elements are in its namespace; 2.0's in none.
            $own = static fn (string $name): string => self::text($xpath, $item, $item->namespaceURI === self::RSS1 ? 'r1:' . $name : $name);
            $link = $own('link');
            $summary = $own('description');
            $categories = [];
            foreach (self::elements($xpath, 'category|dc:subject', $item) as $category) {
                $categories[] = self::clean($category->textContent);
            }
            $published = $own('pubDate') ?: self::text($xpath, $item, 'dc:date');
            $items[] = self::item(
                id: $own('guid') ?: ($item->getAttributeNS(self::RDF, 'about') ?: self::url($link)),
                title: $own('title'),
                link: $link,
                summary: $summary,
                content: self::text($xpath, $item, 'content:encoded') ?: $summary,
                published: $published,
                updated: '',
                author: self::text($xpath, $item, 'dc:creator') ?: $own('author'),
                categories: $categories,
                enclosure: (self::elements($xpath, 'enclosure', $item)[0] ?? null)?->getAttribute('url') ?? '',
            );
        }

        return $items;
    }

    /** @return list<array<string, string|list<string>>> */
    private static function atom(DOMXPath $xpath): array
    {
        $items = [];
        foreach (self::elements($xpath, '/a:feed/a:entry') as $entry) {
            $link = '';
            $enclosure = '';
            foreach (self::elements($xpath, 'a:link', $entry) as $element) {
                $rel = $element->getAttribute('rel') ?: 'alternate';
                if ($rel === 'alternate' && $link === '') {
                    $link = $element->getAttribute('href');
                } elseif ($rel === 'enclosure' && $enclosure === '') {
                    $enclosure = $element->getAttribute('href');
                }
            }
            $summary = self::atomHtml($xpath, $entry, 'a:summary');
            $categories = [];
            foreach (self::elements($xpath, 'a:category', $entry) as $category) {
                $categories[] = self::clean($category->getAttribute('label') ?: $category->getAttribute('term'));
            }
            $updated = self::text($xpath, $entry, 'a:updated');
            $items[] = self::item(
                id: self::text($xpath, $entry, 'a:id') ?: self::url($link),
                title: strip_tags(html_entity_decode(self::atomHtml($xpath, $entry, 'a:title'), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                link: $link,
                summary: $summary,
                content: self::atomHtml($xpath, $entry, 'a:content') ?: $summary,
                published: self::text($xpath, $entry, 'a:published') ?: $updated,
                updated: $updated,
                author: self::text($xpath, $entry, 'a:author/a:name') ?: self::text($xpath, $entry, 'a:author/a:email'),
                categories: $categories,
                enclosure: $enclosure,
            );
        }

        return $items;
    }

    /**
     * @param list<string> $categories
     * @return array<string, string|list<string>>
     */
    private static function item(string $id, string $title, string $link, string $summary, string $content, string $published, string $updated, string $author, array $categories, string $enclosure): array
    {
        $title = self::clean(strip_tags($title));
        $published = self::date($published);
        if ($id === '') {
            $id = 'sha1:' . sha1($title . "\n" . $published);
        } elseif (strlen($id) > 191) {
            $id = 'sha1:' . sha1($id);
        }

        return [
            'id' => $id,
            'title' => $title,
            'link' => self::url($link),
            'summary' => trim($summary),
            'content' => trim($content),
            'published' => $published,
            'updated' => self::date($updated),
            'author' => self::clean($author),
            'categories' => array_values(array_unique(array_filter($categories, static fn (string $c): bool => $c !== ''))),
            'enclosure' => self::url($enclosure),
        ];
    }

    /** An Atom text construct as HTML: `html` as it is, `xhtml` its markup, `text` escaped. */
    private static function atomHtml(DOMXPath $xpath, DOMElement $entry, string $name): string
    {
        $element = self::elements($xpath, $name, $entry)[0] ?? null;
        if ($element === null) {
            return '';
        }
        $type = $element->getAttribute('type') ?: 'text';
        if ($type === 'xhtml') {
            $html = '';
            $div = self::elements($xpath, '*', $element)[0] ?? $element;
            foreach ($div->childNodes as $child) {
                $html .= (string) $element->ownerDocument?->saveXML($child);
            }

            return trim($html);
        }
        $text = trim($element->textContent);

        return $type === 'html' ? $text : nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
    }

    /** @return list<DOMElement> */
    private static function elements(DOMXPath $xpath, string $path, ?DOMNode $context = null): array
    {
        $found = $context === null ? $xpath->query($path) : $xpath->query($path, $context);
        $elements = [];
        foreach ($found ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function text(DOMXPath $xpath, DOMElement $context, string $path): string
    {
        $found = self::elements($xpath, $path, $context);

        return $found === [] ? '' : trim($found[0]->textContent);
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** A date in any form a feed writes it (RFC 822, ISO 8601), as ISO 8601; '' if none. */
    private static function date(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $time = date_create_immutable($text);

        return $time === false ? '' : $time->format(DATE_ATOM);
    }

    /** Only a web address (http, https), else ''. */
    private static function url(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://[^\s<>"]+$#i', $url) === 1 ? $url : '';
    }
}

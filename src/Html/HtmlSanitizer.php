<?php

declare(strict_types=1);

namespace Campanella\Html;

use Campanella\I18n\Message;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Removes everything from an HTML text that is not on the allowlist
 * (config/html.php): scripts, styles, event handlers, javascript: links,
 * frames, forms, and every element and attribute not listed.
 *
 * Runs on save in the ObjectRepository, so no way of saving (admin, CLI,
 * seed, a later API) can store unfiltered HTML. The rendering trusts the stored text.
 *
 * Elements that are not allowed are removed together with their content,
 * except the harmless wrappers in UNWRAPPED (span, font, section…): those are
 * removed but their text is kept, because pasted content often wraps text in them.
 *
 * Built on symfony/html-sanitizer (MIT), which parses like a browser (HTML5),
 * so the result is what a browser would see.
 */
final class HtmlSanitizer
{
    /**
     * Elements that are not allowed, but whose text is kept (the element itself is
     * removed). Any other element that is not allowed is removed with its content,
     * so e.g. the text of a script, a style or a form never appears.
     */
    public const array UNWRAPPED = [
        'span', 'font', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'center',
        'small', 'big', 'u', 'ins', 'del', 'mark', 'abbr', 'cite', 'dfn', 'kbd', 'samp', 'var', 'q',
        'time', 'data', 'bdi', 'bdo', 'address', 'h1', 'h5', 'h6', 'dl', 'dt', 'dd', 'label',
    ];

    private readonly SymfonySanitizer $sanitizer;

    private readonly int $maxLength;

    private readonly int $maxTags;

    /**
     * @param array{elements?: array<string, list<string>>, link_schemes?: list<string>, external_images?: bool, max_length?: int, max_tags?: int} $config
     *        The allowlist (config/html.php); keys left out keep their default.
     */
    public function __construct(array $config = [])
    {
        $config += self::defaults();
        $this->maxLength = $config['max_length'];
        $this->maxTags = $config['max_tags'];
        if ($this->maxLength < 1 || $this->maxTags < 1) {
            throw new \InvalidArgumentException('html max_length and max_tags must be at least 1.');
        }

        // Elements not listed are dropped with their content. Our own length check
        // comes first (isTooLong()), so the library never truncates a text.
        $symfony = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Drop)
            ->withMaxInputLength(-1)
            ->allowLinkSchemes($config['link_schemes'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowMediaSchemes($config['external_images'] ? ['http', 'https'] : [])
            ->forceAttribute('a', 'rel', 'noopener noreferrer');
        if (!$config['external_images']) {
            $symfony = $symfony->withAttributeSanitizer(new LocalMediaSanitizer());
        }
        foreach (self::UNWRAPPED as $element) {
            if (!isset($config['elements'][$element])) {
                $symfony = $symfony->blockElement($element);
            }
        }
        foreach ($config['elements'] as $element => $attributes) {
            $symfony = $symfony->allowElement($element, $attributes);
        }
        $this->sanitizer = new SymfonySanitizer($symfony);
    }

    /**
     * The built-in allowlist (config/html.php ships with the same values).
     *
     * @return array{elements: array<string, list<string>>, link_schemes: list<string>, external_images: bool, max_length: int, max_tags: int}
     */
    public static function defaults(): array
    {
        return [
            'elements' => [
                'p' => [], 'div' => [], 'br' => [], 'hr' => [],
                'h2' => [], 'h3' => [], 'h4' => [],
                'strong' => [], 'b' => [], 'em' => [], 'i' => [], 's' => [], 'sub' => [], 'sup' => [],
                'ul' => [], 'ol' => [], 'li' => [],
                'blockquote' => [], 'code' => [], 'pre' => [],
                'a' => ['href', 'title'],
                'img' => ['src', 'alt', 'title', 'width', 'height'],
                'figure' => [], 'figcaption' => [],
                'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
                'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
            ],
            'link_schemes' => ['http', 'https', 'mailto'],
            'external_images' => false,
            'max_length' => 1_000_000,
            'max_tags' => 20_000,
        ];
    }

    /**
     * The allowed part of the HTML. Idempotent: sanitizing the result again gives the same text.
     *
     * @throws \InvalidArgumentException with the message key of the problem if the text cannot be
     *         filtered (check with problem() first): too long, too many tags, not valid UTF-8
     */
    public function sanitize(string $html): string
    {
        $problem = $this->problem($html);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem->key);
        }
        if (trim($html) === '') {
            return '';
        }
        $clean = trim($this->sanitizer->sanitize($html));

        // The library adds a space to attribute values containing a backtick (a workaround
        // for Internet Explorer 8), which would grow on every save; removed for idempotence.
        return (string) preg_replace('/="([^"]*&#96;[^"]*) "/', '="$1"', $clean);
    }

    /**
     * Why the text cannot be filtered, or null if it can: longer than max_length bytes,
     * more tags than max_tags (deeply nested markup makes parsing very slow), or not
     * valid UTF-8 (the library would return an empty text).
     */
    public function problem(string $html): ?Message
    {
        return match (true) {
            strlen($html) > $this->maxLength => new Message('validation.html_too_long', ['max' => intdiv($this->maxLength, 1000)]),
            substr_count($html, '<') > $this->maxTags => new Message('validation.html_too_many_tags', ['max' => $this->maxTags]),
            !mb_check_encoding($html, 'UTF-8') => new Message('validation.invalid_encoding'),
            default => null,
        };
    }

    /** Whether the text is longer than the max_length setting (in bytes). */
    public function isTooLong(string $html): bool
    {
        return strlen($html) > $this->maxLength;
    }

    /** The longest HTML text accepted, in bytes. */
    public function maxLength(): int
    {
        return $this->maxLength;
    }
}

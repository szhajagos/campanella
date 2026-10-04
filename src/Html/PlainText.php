<?php

declare(strict_types=1);

namespace Campanella\Html;

/**
 * Plain text as HTML, the same way a plain text is shown on the site: blank
 * lines separate paragraphs (<p>), a single line break stays a line break
 * (<br>), and every character is escaped. Used when a plain text is converted
 * to a formatted (HTML) text, so nothing of it is lost.
 */
final class PlainText
{
    public static function toHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split('/\n[ \t]*\n\s*/', $text) ?: [];

        return implode('', array_map(
            static fn (string $p): string => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }
}

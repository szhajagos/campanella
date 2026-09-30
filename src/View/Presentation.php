<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Model\CampanellaObject;
use Campanella\Query\ResultSet;
use Twig\Environment;

/**
 * Decides HOW something is rendered, and picks the template for it.
 *
 * Object modes: full, teaser (later card, table_row, hero…).
 * Template lookup goes from the most specific to the general:
 *
 *   object/article--teaser.html.twig   (Blueprint + mode)
 *   object/teaser.html.twig            (mode only)
 *
 * Likewise for lists: query/news.html.twig → query/list.html.twig
 *
 * Presentation does not query the database; it only renders what it gets.
 */
final class Presentation
{
    public const string FULL = 'full';
    public const string TEASER = 'teaser';

    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param array<string, mixed> $context */
    public function renderObject(CampanellaObject $object, string $mode = self::TEASER, array $context = []): string
    {
        $template = $this->twig->resolveTemplate([
            sprintf('object/%s--%s.html.twig', $object->blueprint(), $mode),
            sprintf('object/%s.html.twig', $mode),
        ]);

        return $template->render(['object' => $object, 'mode' => $mode] + $context);
    }

    /** @param array<string, mixed> $context */
    public function renderList(ResultSet $result, string $name, string $itemMode = self::TEASER, array $context = []): string
    {
        $template = $this->twig->resolveTemplate([
            sprintf('query/%s.html.twig', $name),
            'query/list.html.twig',
        ]);

        return $template->render(['result' => $result, 'name' => $name, 'item_mode' => $itemMode] + $context);
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}

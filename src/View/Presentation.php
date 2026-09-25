<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Model\CampanellaObject;
use Campanella\Query\ResultSet;
use Twig\Environment;

/**
 * Megmondja, HOGYAN jelenjen meg valami, és ehhez kiválasztja a sablont.
 *
 * Objektum-módok: full, teaser (később card, table_row, hero…).
 * A sablonkeresés a legspecifikusabbtól halad az általános felé:
 *
 *   object/article--teaser.html.twig   (Blueprint + mód)
 *   object/teaser.html.twig            (csak mód)
 *
 * Listáknál ugyanígy: query/news.html.twig → query/list.html.twig
 *
 * A Presentation nem kérdez adatbázist, csak azt jeleníti meg, amit kap.
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

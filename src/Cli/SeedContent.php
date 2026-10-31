<?php

declare(strict_types=1);

namespace Campanella\Cli;

/**
 * The sample content of `seed` in one language (since 0.3.7): `resources/seed/<locale>.json`.
 * A new language is a new file, no code. The file is checked whole before anything is made:
 * every text where a text is expected, the machine names, the categories the articles name,
 * the events' fields in their Blueprint; a problem names its place (`articles.2.lead`).
 *
 *   {"version": 1, "language": "English",
 *    "categories": {"science": {"title": …, "body": …}, …},
 *    "articles": [{"title", "when" (e.g. "-5 days"), "lead", "body", "categories": [keys]}, …],
 *    "draft": {"title", "body"}, "about": {"title", "body"},
 *    "events": {"blueprint_name", "list_name", "fields": {"start", "kind", "place", "price"},
 *               "blueprint": {a Blueprint as the content model stores it}, "list": {a list, likewise},
 *               "samples": [{"title", "days", "time", "kind", "place", "price", "body"}, …]},
 *    "menu": {"title", "home", "news", "categories", "events"} (the links' texts; the paths are
 *            the site's: ContentPaths),
 *    "front_page": {"title", "body", "news_list_name", "news_list": {a list},
 *                   "contact": {"title", "body"},
 *                   "components": {"news", "events", "contact": {"title", "machine_name"}}},
 *    "color_mode_switch": {"title", "machine_name"}}
 *
 * The texts are plain text: an empty line starts a new paragraph.
 */
final readonly class SeedContent
{
    public const int VERSION = 1;

    /** The language used for a locale that has no file of its own. */
    public const string FALLBACK = 'en';

    /**
     * @param array<string, array{title: string, body: string}> $categories key => category
     * @param list<array{title: string, when: string, lead: string, body: string, categories: list<string>}> $articles
     * @param array{title: string, body: string} $draft
     * @param array{title: string, body: string} $about
     * @param array{start: string, kind: string, place: string, price: string} $eventFields role => the field's name
     * @param array<string, mixed> $eventBlueprint
     * @param array<string, mixed> $eventList
     * @param list<array{title: string, days: int, time: string, kind: string, place: string, price: string, body: string}> $eventSamples
     * @param array{title: string, home: string, news: string, categories: string, events: string} $menu
     * @param array{title: string, body: string} $frontPage
     * @param array<string, mixed> $newsList
     * @param array{title: string, body: string} $contact
     * @param array{news: array{title: string, machine_name: string}, events: array{title: string, machine_name: string}, contact: array{title: string, machine_name: string}} $components
     * @param array{title: string, machine_name: string} $colorModeSwitch
     */
    public function __construct(
        public string $locale,
        public string $language,
        public array $categories,
        public array $articles,
        public array $draft,
        public array $about,
        public string $eventBlueprintName,
        public string $eventListName,
        public array $eventFields,
        public array $eventBlueprint,
        public array $eventList,
        public array $eventSamples,
        public array $menu,
        public array $frontPage,
        public string $newsListName,
        public array $newsList,
        public array $contact,
        public array $components,
        public array $colorModeSwitch,
    ) {
    }

    /** The folder of the core's files. */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/resources/seed';
    }

    /**
     * The languages there are files for.
     *
     * @return list<string>
     */
    public static function available(?string $directory = null): array
    {
        $names = array_map(static fn (string $file): string => basename($file, '.json'), glob(($directory ?? self::directory()) . '/*.json') ?: []);
        sort($names);

        return array_values(array_filter($names, static fn (string $n): bool => preg_match('/^[a-z]{2}(_[A-Z]{2})?$/D', $n) === 1));
    }

    /**
     * The file for the locale: its own (`hu_HU`), its language's (`hu`), else English, else
     * the first there is (the locale `locale` of the result says which one).
     *
     * @throws \InvalidArgumentException for a locale that is not one, a missing or invalid file
     */
    public static function forLocale(string $locale, ?string $directory = null): self
    {
        if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/D', $locale) !== 1) {
            throw new \InvalidArgumentException("Not a locale: {$locale} (e.g. en, hu).");
        }
        $directory ??= self::directory();
        foreach (array_unique([$locale, substr($locale, 0, 2), self::FALLBACK]) as $candidate) {
            $file = $directory . '/' . $candidate . '.json';
            if (is_file($file)) {
                return self::fromJson($candidate, (string) file_get_contents($file));
            }
        }
        // No file for it, nor English: the first there is.
        $any = self::available($directory)[0] ?? null;
        if ($any !== null) {
            return self::fromJson($any, (string) file_get_contents($directory . '/' . $any . '.json'));
        }
        throw new \InvalidArgumentException("There is no sample content for {$locale} (resources/seed/).");
    }

    /** @throws \InvalidArgumentException if the JSON is not valid sample content */
    public static function fromJson(string $locale, string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Sample content {$locale}: not valid JSON ({$e->getMessage()}).");
        }
        $r = new SeedReader($locale, is_array($data) ? $data : []);
        if ($r->int('version') !== self::VERSION) {
            throw $r->problem('version', 'must be ' . self::VERSION);
        }

        $categories = [];
        foreach ($r->map('categories') as $key => $_) {
            $categories[$r->key('categories', $key)] = $r->titled('categories.' . $key);
        }
        $articles = [];
        foreach (array_keys($r->items('articles')) as $i) {
            $at = 'articles.' . $i;
            $names = $r->strings($at . '.categories');
            foreach ($names as $name) {
                if (!isset($categories[$name])) {
                    throw $r->problem($at . '.categories', "names a category that is not given ({$name})");
                }
            }
            $articles[] = ['title' => $r->text($at . '.title'), 'when' => $r->when($at . '.when'), 'lead' => $r->text($at . '.lead'), 'body' => $r->text($at . '.body'), 'categories' => $names];
        }

        $fields = [];
        foreach (['start', 'kind', 'place', 'price'] as $role) {
            $fields[$role] = $r->name('events.fields.' . $role);
        }
        $blueprint = $r->map('events.blueprint');
        $declared = array_column(is_array($blueprint['fields'] ?? null) ? array_filter($blueprint['fields'], 'is_array') : [], 'name');
        foreach ($fields as $role => $field) {
            if (!in_array($field, $declared, true)) {
                throw $r->problem('events.fields.' . $role, "is not a field of the events' Blueprint ({$field})");
            }
        }
        $samples = [];
        foreach (array_keys($r->items('events.samples')) as $i) {
            $at = 'events.samples.' . $i;
            $samples[] = [
                'title' => $r->text($at . '.title'), 'days' => $r->int($at . '.days'), 'time' => $r->time($at . '.time'),
                'kind' => $r->name($at . '.kind'), 'place' => $r->text($at . '.place'), 'price' => $r->number($at . '.price'), 'body' => $r->text($at . '.body'),
            ];
        }

        $component = static fn (string $at): array => ['title' => $r->text($at . '.title'), 'machine_name' => $r->name($at . '.machine_name')];

        return new self(
            $locale,
            $r->text('language'),
            $categories,
            $articles,
            $r->titled('draft'),
            $r->titled('about'),
            $r->name('events.blueprint_name'),
            $r->name('events.list_name'),
            $fields,
            $blueprint,
            $r->map('events.list'),
            $samples,
            [
                'title' => $r->text('menu.title'), 'home' => $r->text('menu.home'), 'news' => $r->text('menu.news'),
                'categories' => $r->text('menu.categories'), 'events' => $r->text('menu.events'),
            ],
            $r->titled('front_page'),
            $r->name('front_page.news_list_name'),
            $r->map('front_page.news_list'),
            $r->titled('front_page.contact'),
            ['news' => $component('front_page.components.news'), 'events' => $component('front_page.components.events'), 'contact' => $component('front_page.components.contact')],
            $component('color_mode_switch'),
        );
    }
}

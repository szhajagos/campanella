<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Layout\LayoutDefinition;
use Campanella\Layout\LayoutService;
use Campanella\Layout\RegionDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Closure;

/**
 * The layouts in the admin (since 0.3.4), under /admin/system/layouts, for the system
 * roles (Appearance → Layouts): the site's layout, the Blueprints' own (those whose
 * objects have pages), and how each layout's regions show on small screens. The rules
 * are LayoutService::save()'s.
 */
final class LayoutPages
{
    public function __construct(
        private readonly LayoutService $layouts,
        private readonly BlueprintRegistry $blueprints,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /**
     * The looks offered for a region on small screens: the value in the form => its parts.
     *
     * @return array<string, array{small: string, breakpoint: ?string}>
     */
    public static function looks(): array
    {
        $looks = [RegionDefinition::ALWAYS => ['small' => RegionDefinition::ALWAYS, 'breakpoint' => null]];
        foreach ([RegionDefinition::COLLAPSE, RegionDefinition::HIDE] as $small) {
            foreach (RegionDefinition::BREAKPOINTS as $breakpoint) {
                $looks[$small . ':' . $breakpoint] = ['small' => $small, 'breakpoint' => $breakpoint];
            }
        }

        return $looks;
    }

    /**
     * @param list<string> $segments The path after /admin/system/layouts
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        if ($segments !== []) {
            throw HttpException::notFound();
        }
        if (!$request->isPost()) {
            return $render('layouts', 'layouts', $this->context($this->current(), []));
        }

        $form = $this->posted($request);
        if (!$this->csrf->isValid($request)) {
            return self::status($render('layouts', 'layouts', $this->context($form, []) + ['alert' => 'auth.form_expired']), 400);
        }
        $looks = [];
        foreach ($form['looks'] as $layout => $regions) {
            foreach ($regions as $region => $look) {
                // "always" has no breakpoint of its own: the layout's is kept with it.
                $default = $this->layouts->registry()->has($layout) ? ($this->layouts->registry()->get($layout)->regions[$region] ?? null) : null;
                $looks[$layout][$region] = $look === RegionDefinition::ALWAYS ? $look . ':' . ($default->breakpoint ?? 'md') : $look;
            }
        }
        try {
            $this->layouts->save($form['site'], $form['blueprints'], $looks);
        } catch (ValidationException $e) {
            return self::status($render('layouts', 'layouts', $this->context($form, $e->errors) + ['alert' => 'admin.form.invalid']), 422);
        }
        $this->flash->add(Flash::SUCCESS, 'layouts.saved');

        return Response::redirect($request->basePath . $this->access->path('system/layouts'), 303);
    }

    /**
     * The saved choices, as the form shows them.
     *
     * @return array{site: string, blueprints: array<string, string>, looks: array<string, array<string, string>>}
     */
    private function current(): array
    {
        $blueprints = [];
        foreach (array_keys($this->routable()) as $name) {
            $blueprints[$name] = $this->layouts->blueprintChoice($name) ?? '';
        }
        $looks = [];
        foreach ($this->layouts->registry()->all() as $layout) {
            foreach ($this->layouts->withLooks($layout)->regions as $name => $region) {
                $looks[$layout->name][$name] = self::lookValue($region);
            }
        }

        return ['site' => $this->layouts->site()->name, 'blueprints' => $blueprints, 'looks' => $looks];
    }

    /**
     * The form as sent: only the Blueprints and regions there are (anything else is left out).
     *
     * @return array{site: string, blueprints: array<string, string>, looks: array<string, array<string, string>>}
     */
    private function posted(Request $request): array
    {
        $current = $this->current();
        $sentBlueprints = is_array($request->post['blueprint'] ?? null) ? $request->post['blueprint'] : [];
        $sentLooks = is_array($request->post['look'] ?? null) ? $request->post['look'] : [];
        $blueprints = [];
        foreach (array_keys($current['blueprints']) as $name) {
            $value = $sentBlueprints[$name] ?? null;
            $blueprints[$name] = is_string($value) ? $value : $current['blueprints'][$name];
        }
        $looks = [];
        foreach ($current['looks'] as $layout => $regions) {
            foreach (array_keys($regions) as $region) {
                $value = is_array($sentLooks[$layout] ?? null) ? ($sentLooks[$layout][$region] ?? null) : null;
                $looks[$layout][$region] = is_string($value) && isset(self::looks()[$value]) ? $value : (is_string($value) ? 'invalid' : $regions[$region]);
            }
        }

        return ['site' => $request->postString('site'), 'blueprints' => $blueprints, 'looks' => $looks];
    }

    /**
     * @param array{site: string, blueprints: array<string, string>, looks: array<string, array<string, string>>} $form
     * @param array<string, mixed> $errors
     * @return array<string, mixed>
     */
    private function context(array $form, array $errors): array
    {
        $layouts = $this->layouts->registry()->all();

        return [
            'title' => 'layouts.title',
            'form' => $form,
            'errors' => $errors,
            'enabled' => $this->layouts->isEnabled(),
            'layouts' => array_map(static fn (LayoutDefinition $l): array => [
                'name' => $l->name,
                'label' => $l->label,
                'origin' => $l->origin,
                'regions' => array_map(static fn (RegionDefinition $r): array => ['name' => $r->name, 'label' => $r->label, 'default' => self::lookValue($r)], $l->regions),
            ], $layouts),
            'broken' => $this->layouts->registry()->errors(),
            'blueprints' => array_map(static fn ($b): array => ['name' => $b->name, 'label' => $b->label], $this->routable()),
            'looks' => self::looks(),
        ];
    }

    /** @return array<string, \Campanella\Model\Blueprint> The Blueprints whose objects have pages of their own */
    private function routable(): array
    {
        return array_filter($this->blueprints->all(), static fn ($b): bool => isset($b->capabilities['routable']));
    }

    private static function lookValue(RegionDefinition $region): string
    {
        return $region->small === RegionDefinition::ALWAYS ? RegionDefinition::ALWAYS : $region->small . ':' . $region->breakpoint;
    }

    private static function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}

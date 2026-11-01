<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Appearance\Appearance;
use Campanella\Appearance\ColorScheme;
use Campanella\Appearance\ColorSchemeRegistry;
use Campanella\Appearance\ColorSchemeStore;
use Campanella\I18n\Message;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Closure;

/**
 * The colour schemes in the admin (since 0.3.6), under /admin/system/color-schemes, for the
 * system roles (Appearance → Colour schemes): the site's scheme, each with a preview of its
 * light and dark variant, and the colour mode. The schemes left out (not valid, or hard to
 * read) are listed with the reason. The rules are Appearance::save()'s.
 */
final class ColorSchemePages
{
    public const string PATH = 'system/color-schemes';

    public function __construct(
        private readonly Appearance $appearance,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
        private readonly ?ColorSchemeStore $store = null,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/color-schemes
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        // A scheme made in the admin (since 0.4.2): new, its form, deleting it.
        if ($segments !== [] && $this->store !== null) {
            $name = $segments[0];
            return match (true) {
                $segments === ['new'] => $this->form($request, null, $render),
                count($segments) === 1 && $this->isOwn($name) => $this->form($request, $name, $render),
                count($segments) === 2 && $segments[1] === 'delete' && $this->isOwn($name) => $this->delete($request, $name, $render),
                default => throw HttpException::notFound(),
            };
        }
        if ($segments !== []) {
            throw HttpException::notFound();
        }
        if (!$request->isPost()) {
            return $render('color_schemes', 'color_schemes', $this->context(['scheme' => $this->appearance->scheme()->name, 'color_mode' => $this->appearance->siteMode()], []));
        }

        $form = ['scheme' => $request->postString('scheme'), 'color_mode' => $request->postString('color_mode')];
        if (!$this->csrf->isValid($request)) {
            return self::status($render('color_schemes', 'color_schemes', $this->context($form, []) + ['alert' => 'auth.form_expired']), 400);
        }
        try {
            $this->appearance->save($form['scheme'], $form['color_mode']);
        } catch (ValidationException $e) {
            return self::status($render('color_schemes', 'color_schemes', $this->context($form, $e->errors) + ['alert' => 'admin.form.invalid']), 422);
        }
        $this->flash->add(Flash::SUCCESS, 'appearance.saved');

        return Response::redirect($request->basePath . $this->access->path(self::PATH), 303);
    }

    /** Whether the name is a scheme made in the admin. */
    private function isOwn(string $name): bool
    {
        $scheme = $this->appearance->registry()->all()[$name] ?? null;

        return $scheme !== null && $scheme->origin === 'admin';
    }

    /**
     * A scheme's form (since 0.4.2): its name (a new one's only), its label, its eight colours
     * in both variants, with a preview; a new one starts from a copy (`?from=<scheme>`).
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function form(Request $request, ?string $name, Closure $render): Response
    {
        $registry = $this->appearance->registry();
        $base = $name !== null ? $registry->get($name) : $registry->get($request->queryString('from') ?: ColorSchemeRegistry::DEFAULT);
        $form = [
            'name' => $name ?? '',
            'label' => $name !== null ? $base->label : '',
            'light' => $base->light,
            'dark' => $base->dark,
        ];
        $context = ['title' => $name === null ? 'appearance.new' : 'appearance.edit', 'is_new' => $name === null, 'colors' => array_keys(ColorScheme::COLORS), 'from' => $name === null ? $base->name : null];
        if (!$request->isPost()) {
            return $render('color_scheme_form', 'color_schemes', $context + ['form' => $form, 'errors' => []]);
        }

        $posted = static fn (string $variant): array => is_array($request->post[$variant] ?? null) ? array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $request->post[$variant]) : [];
        $form = [
            'name' => $name ?? $request->postString('name'),
            'label' => trim($request->postString('label')),
            'light' => array_intersect_key($posted('light'), ColorScheme::COLORS) + array_fill_keys(array_keys(ColorScheme::COLORS), ''),
            'dark' => array_intersect_key($posted('dark'), ColorScheme::COLORS) + array_fill_keys(array_keys(ColorScheme::COLORS), ''),
        ];
        if (!$this->csrf->isValid($request)) {
            return self::status($render('color_scheme_form', 'color_schemes', $context + ['form' => $form, 'errors' => [], 'alert' => 'auth.form_expired']), 400);
        }
        $errors = array_map(static fn (array $p): Message => new Message($p['key'], $p['params']), ColorScheme::problems($form['light'], $form['dark']));
        if ($name === null) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $form['name']) !== 1) {
                $errors['name'] = new Message('appearance.name_invalid');
            } elseif ($registry->has($form['name']) || isset($registry->errors()[$form['name']])) {
                $errors['name'] = new Message('appearance.name_taken');
            }
        }
        if ($form['label'] === '' || mb_strlen($form['label']) > 100) {
            $errors['label'] = new Message('appearance.label_invalid');
        }
        $scheme = null;
        if ($errors === []) {
            try {
                // The same check as a scheme's file has, once more.
                $scheme = ColorScheme::fromJson($form['name'], (string) json_encode(['version' => ColorScheme::VERSION, 'label' => $form['label'], 'light' => $form['light'], 'dark' => $form['dark']]), 'admin');
            } catch (\InvalidArgumentException) {
                $errors['label'] = new Message('admin.form.invalid');
            }
        }
        if ($scheme === null) {
            return self::status($render('color_scheme_form', 'color_schemes', $context + ['form' => $form, 'errors' => $errors, 'alert' => 'admin.form.invalid']), 422);
        }
        $this->store?->save($scheme);
        $registry->reset();
        $this->flash->add(Flash::SUCCESS, new Message('appearance.scheme_saved', ['label' => $scheme->label]));

        return Response::redirect($request->basePath . $this->access->path(self::PATH), 303);
    }

    /**
     * Deleting a scheme made in the admin; not the site's own (choose another first).
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function delete(Request $request, string $name, Closure $render): Response
    {
        $scheme = $this->appearance->registry()->get($name);
        $inUse = $this->appearance->scheme()->name === $name;
        if (!$request->isPost()) {
            return $render('color_scheme_delete', 'color_schemes', ['title' => 'appearance.delete', 'scheme' => ['name' => $name, 'label' => $scheme->label], 'in_use' => $inUse]);
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');
        } elseif ($inUse) {
            $this->flash->add(Flash::DANGER, 'appearance.delete_in_use');
        } else {
            $this->store?->delete($name);
            $this->appearance->registry()->reset();
            $this->flash->add(Flash::SUCCESS, new Message('appearance.deleted', ['label' => $scheme->label]));
        }

        return Response::redirect($request->basePath . $this->access->path(self::PATH), 303);
    }

    /**
     * @param array{scheme: string, color_mode: string} $form
     * @param array<string, mixed> $errors
     * @return array<string, mixed>
     */
    private function context(array $form, array $errors): array
    {
        return [
            'can_make' => $this->store !== null,
            'title' => 'appearance.title',
            'form' => $form,
            'errors' => $errors,
            'schemes' => array_map(static fn (ColorScheme $s): array => [
                'name' => $s->name,
                'label' => $s->label,
                'origin' => $s->origin,
                'light' => $s->light,
                'dark' => $s->dark,
            ], $this->appearance->registry()->all()),
            'broken' => $this->appearance->registry()->errors(),
            'missing' => $this->appearance->missingScheme(),
            'modes' => Appearance::MODES,
        ];
    }

    private static function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}

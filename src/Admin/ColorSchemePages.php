<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Appearance\Appearance;
use Campanella\Appearance\ColorScheme;
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
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/color-schemes
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
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

    /**
     * @param array{scheme: string, color_mode: string} $form
     * @param array<string, mixed> $errors
     * @return array<string, mixed>
     */
    private function context(array $form, array $errors): array
    {
        return [
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

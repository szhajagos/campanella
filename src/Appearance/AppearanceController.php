<?php

declare(strict_types=1);

namespace Campanella\Appearance;

use Campanella\Access\Actor;
use Campanella\Controller\AuthController;
use Campanella\Controller\Controller;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;

/**
 * The look's two addresses (since 0.3.6):
 *
 *   /color-scheme.css   the site's colour scheme as CSS. Linked with its version (`?v=`, see
 *                       Appearance::version()), so a browser keeps it for a year and gets the
 *                       new one when the scheme changes; without it, or with an old one, for
 *                       five minutes.
 *   POST /color-mode    the visitor's own colour mode (`mode`: auto, light, dark), from the
 *                       colour mode switch: a cookie for a year, then back to the page
 *                       (`return`, a path of this site). No session and no CSRF token, so a
 *                       page with the switch stays the same for every visitor; a request from
 *                       another site is refused (Origin, Sec-Fetch-Site), so it cannot change
 *                       a visitor's mode.
 */
final class AppearanceController implements Controller
{
    public const string PATH = '/color-scheme.css';

    public const string MODE_PATH = '/color-mode';

    /** How long the visitor's choice is kept: a year. */
    public const int COOKIE_LIFETIME = 31536000;

    public function __construct(private readonly Appearance $appearance)
    {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        return ($route->params['action'] ?? 'css') === 'mode' ? $this->mode($request) : $this->css($request);
    }

    private function css(Request $request): Response
    {
        $current = ($request->query['v'] ?? null) === $this->appearance->version();

        return new Response($this->appearance->css(), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function mode(Request $request): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!self::isSameSite($request)) {
            throw new HttpException(403, 'error.forbidden');
        }
        $mode = $request->postString('mode');
        if (!in_array($mode, Appearance::MODES, true)) {
            throw new HttpException(400, 'appearance.mode_invalid');
        }
        $cookie = sprintf(
            '%s=%s; Path=%s; Max-Age=%d; SameSite=Lax; HttpOnly%s',
            Appearance::COOKIE,
            $mode,
            $request->basePath === '' ? '/' : $request->basePath . '/',
            self::COOKIE_LIFETIME,
            $request->secure ? '; Secure' : '',
        );

        return Response::redirect($request->basePath . AuthController::safeTarget($request->postString('return')), 303)
            ->withHeader('Set-Cookie', $cookie)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Whether the request came from this site's own page: a browser says where a form was
     * sent from (Sec-Fetch-Site; Origin in older ones). A request saying neither (not a
     * browser) is let through: it can only change its own cookie.
     */
    private static function isSameSite(Request $request): bool
    {
        $fetch = $request->headers['sec-fetch-site'] ?? null;
        if ($fetch !== null) {
            return in_array($fetch, ['same-origin', 'none'], true);
        }
        $origin = $request->headers['origin'] ?? null;
        if ($origin === null || $origin === '') {
            return true;
        }
        $host = $request->headers['host'] ?? '';

        return $origin !== 'null' && parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) !== null ? ':' . parse_url($origin, PHP_URL_PORT) : '') === $host;
    }
}

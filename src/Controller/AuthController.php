<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\I18n\Translator;
use Campanella\Security\Csrf;
use Campanella\View\Presentation;

/**
 * Login and logout, at the paths of the `paths` setting (SitePaths; /login and
 * /logout by default). After logging in, back to the `return` parameter's path
 * (a path of this site only).
 *
 * Logout only works with a POST request and a CSRF token, so a foreign site
 * cannot log the visitor out.
 */
final class AuthController implements Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Presentation $presentation,
        private readonly Translator $translator,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        return match ($route->params['action'] ?? '') {
            'logout' => $this->logout($request),
            default => $this->login($request, $actor),
        };
    }

    private function login(Request $request, Actor $actor): Response
    {
        $target = self::safeTarget($request->isPost() ? $request->postString('return') : $request->queryString('return'));

        if (!$actor->isAnonymous()) {
            return Response::redirect($request->basePath . $target);
        }
        if (!$request->isPost()) {
            return $this->form($request, $target);
        }

        $email = $request->postString('email');
        if (!$this->csrf->isValid($request)) {
            return $this->form($request, $target, $email, $this->translator->translate('auth.form_expired'), 400);
        }
        $result = $this->auth->attempt($request, $email, $request->postString('password'));
        if (!$result->success) {
            return $this->form($request, $target, $email, $this->translator->translate($result->error, $result->errorParams), 422);
        }

        return Response::redirect($request->basePath . $target, 303);
    }

    private function logout(Request $request): Response
    {
        if ($request->isPost() && $this->csrf->isValid($request)) {
            $this->auth->logout();
        }

        return Response::redirect($request->basePath . '/', 303);
    }

    private function form(Request $request, string $target, string $email = '', string $error = '', int $status = 200): Response
    {
        return Response::html($this->presentation->render('page/login.html.twig', [
            'title' => $this->translator->translate('auth.login'),
            'email' => $email,
            'error' => $error,
            'target' => $target,
            'guard_fields' => implode('', array_map(static fn ($g): string => $g->fields(), $this->auth->guards())),
        ]), $status)->withHeader('X-Robots-Tag', 'noindex'); // the login page is not content (since 0.1.1)
    }

    /** Only a path within this site is accepted (against open redirects). */
    public static function safeTarget(string $target): string
    {
        if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F]/', $target) === 1) {
            return '/';
        }

        return $target;
    }
}

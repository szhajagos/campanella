<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Security\Csrf;
use Campanella\View\Presentation;

/**
 * Belépés (/belepes) és kilépés (/kilepes).
 *
 * A kilépés csak POST kéréssel, CSRF-tokennel működik, így egy idegen oldal
 * nem tudja a látogatót kiléptetni.
 */
final class AuthController implements Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Presentation $presentation,
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
        $target = self::safeTarget($request->isPost() ? $request->postString('vissza') : $request->queryString('vissza'));

        if (!$actor->isAnonymous()) {
            return Response::redirect($request->basePath . $target);
        }
        if (!$request->isPost()) {
            return $this->form($request, $target);
        }

        $email = $request->postString('email');
        if (!$this->csrf->isValid($request)) {
            return $this->form($request, $target, $email, 'Az űrlap lejárt. Kérjük, próbáld újra.', 400);
        }
        $result = $this->auth->attempt($request, $email, $request->postString('password'));
        if (!$result->success) {
            return $this->form($request, $target, $email, $result->error, 422);
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
            'title' => 'Belépés',
            'email' => $email,
            'error' => $error,
            'target' => $target,
            'guard_fields' => implode('', array_map(static fn ($g): string => $g->fields(), $this->auth->guards())),
        ]), $status);
    }

    /** Csak a saját oldalon belüli útvonal fogadható el (nyílt átirányítás ellen). */
    public static function safeTarget(string $target): string
    {
        if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F]/', $target) === 1) {
            return '/';
        }

        return $target;
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Auth\PasswordReset;
use Campanella\Capability\Authenticatable;
use Campanella\Http\HttpException;
use Campanella\Http\Session;
use Campanella\Http\SitePaths;
use Campanella\I18n\Message;
use Campanella\Model\ValidationException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\I18n\Translator;
use Campanella\Security\Csrf;
use Campanella\View\Presentation;

/**
 * Login, logout and the forgotten password, at the paths of the `paths` setting
 * (SitePaths; /login, /logout and /password-reset by default). After logging in,
 * back to the `return` parameter's path (a path of this site only).
 *
 * Logout only works with a POST request and a CSRF token, so a foreign site
 * cannot log the visitor out.
 *
 * The forgotten password (since 0.1.4; PasswordReset):
 *   GET  /password-reset              the form: an e-mail address
 *   POST /password-reset              a link is sent (if the address is registered);
 *                                     to /password-reset?sent=1, the same answer either way
 *   GET  /password-reset?token=…      the e-mailed link: the token moves into the session,
 *                                     to /password-reset, so it stays out of the address bar
 *   GET  /password-reset              (with a token in the session) the new password's form
 *   POST /password-reset              (with a token in the session) the new password; to the
 *                                     login page (?reset=1)
 *   GET  /password-reset?again=1      forgets the token in the session: a new request
 * Answers 404 while it is not available (e-mail or the site's address is not set up).
 */
final class AuthController implements Controller
{
    /** The forgotten password's token, in the session between the link and the new password. */
    public const string RESET_TOKEN = 'password_reset_token';

    public function __construct(
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Presentation $presentation,
        private readonly Translator $translator,
        private readonly ?PasswordReset $reset = null,
        private readonly ?Session $session = null,
        private readonly SitePaths $paths = new SitePaths(),
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        return match ($route->params['action'] ?? '') {
            'logout' => $this->logout($request),
            'reset' => $this->reset($request),
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
            'guard_fields' => $this->guardFields(),
            'reset_available' => $this->reset?->isAvailable() ?? false,
            'reset_done' => $request->queryString('reset') === '1',
        ]), $status)->withHeader('X-Robots-Tag', 'noindex'); // the login page is not content (since 0.1.1)
    }

    private function reset(Request $request): Response
    {
        $reset = $this->reset;
        $session = $this->session;
        if ($reset === null || $session === null || !$reset->isAvailable()) {
            throw HttpException::notFound();
        }
        $self = $request->basePath . $this->paths->get('password_reset');

        // The e-mailed link: the token into the session, out of the address.
        $token = $request->queryString('token');
        if ($token !== '') {
            $session->start($request);
            $session->set(self::RESET_TOKEN, substr($token, 0, 200));

            return Response::redirect($self, 303);
        }
        $session->resume($request);
        if ($request->queryString('again') === '1') {
            $session->remove(self::RESET_TOKEN);

            return Response::redirect($self, 303);
        }
        $stored = $session->get(self::RESET_TOKEN);
        if (is_string($stored) && $stored !== '') {
            return $this->newPassword($request, $stored, $reset, $session);
        }
        if (!$request->isPost()) {
            return $this->resetPage($request->queryString('sent') === '1' ? 'sent' : 'request');
        }
        $email = $request->postString('email');
        if (!$this->csrf->isValid($request)) {
            return $this->resetPage('request', ['email' => $email, 'error' => new Message('auth.form_expired')], 400);
        }
        // A guard (e.g. the honeypot) that stops a bot: it gets the usual answer, nothing is sent.
        foreach ($this->auth->guards() as $guard) {
            if ($guard->check($request) !== null) {
                return Response::redirect($self . '?sent=1', 303);
            }
        }
        $error = $reset->request($request, $email);
        if ($error !== null) {
            return $this->resetPage('request', ['email' => $email, 'error' => $error], $error->key === 'auth.reset.too_many' ? 429 : 422);
        }

        return Response::redirect($self . '?sent=1', 303);
    }

    private function newPassword(Request $request, string $token, PasswordReset $reset, Session $session): Response
    {
        if (!$request->isPost()) {
            if ($reset->verify($request, $token) === null) {
                $session->remove(self::RESET_TOKEN);

                return $this->resetPage('invalid', [], 410);
            }

            return $this->resetPage('new');
        }
        if (!$this->csrf->isValid($request)) {
            return $this->resetPage('new', ['error' => new Message('auth.form_expired')], 400);
        }
        $password = $request->postString('password');
        if (!hash_equals($password, $request->postString('password_again'))) {
            return $this->resetPage('new', ['errors' => ['password_again' => new Message('users.password_mismatch')]], 422);
        }
        try {
            $user = $reset->complete($request, $token, $password);
        } catch (ValidationException $e) {
            return $this->resetPage('new', ['errors' => $e->errors], 422);
        }
        $session->remove(self::RESET_TOKEN);
        if ($user === null) {
            return $this->resetPage('invalid', [], 410);
        }

        return Response::redirect($request->basePath . $this->paths->get('login') . '?reset=1', 303);
    }

    /** @param array<string, mixed> $context */
    private function resetPage(string $step, array $context = [], int $status = 200): Response
    {
        return Response::html($this->presentation->render('page/password_reset.html.twig', $context + [
            'title' => $this->translator->translate('auth.reset.title'),
            'step' => $step,
            'email' => '',
            'error' => null,
            'errors' => [],
            'minutes' => $this->reset?->minutes() ?? PasswordReset::DEFAULT_MINUTES,
            'min_password' => Authenticatable::MIN_PASSWORD_LENGTH,
            'guard_fields' => $this->guardFields(),
        ]), $status)->withHeader('X-Robots-Tag', 'noindex');
    }

    private function guardFields(): string
    {
        return implode('', array_map(static fn ($g): string => $g->fields(), $this->auth->guards()));
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

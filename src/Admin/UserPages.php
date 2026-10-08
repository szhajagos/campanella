<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Capability\Authenticatable;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Campanella\Service\UserService;
use Closure;

/**
 * The admin's user pages (since 0.1.0):
 *
 *   /admin/user                   the users (for those who may manage them)
 *   /admin/user/new               a new user
 *   /admin/user/<id>              name, e-mail address, roles, status
 *   POST /admin/user/<id>/password  a new password for the user
 *   /admin/profile                one's own name; POST /admin/profile/password: one's own password
 *
 * The rules are the UserService's; this class only reads the forms and shows the pages.
 * Passwords are never shown again in a form.
 */
final class UserPages
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/user
     * @param Closure(string, string, array<string, mixed>): Response $render template, active menu item, context
     */
    public function users(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        if (!$this->users->canManage($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }

        return match (true) {
            $segments === [] => $this->listing($actor, $render),
            $segments === ['new'] => $this->create($request, $actor, $render),
            count($segments) === 1 => $this->edit($request, $actor, $this->find($actor, $segments[0]), $render),
            count($segments) === 2 && $segments[1] === 'password' => $this->password($request, $actor, $this->find($actor, $segments[0]), $render),
            default => throw HttpException::notFound(),
        };
    }

    /**
     * @param list<string> $segments The path after /admin/profile
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function profile(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        $user = $this->auth->currentUser($request) ?? throw new HttpException(403, 'error.forbidden');
        $context = ['title' => 'users.profile', 'me' => self::row($user), 'errors' => [], 'name' => (string) $user->get('title'), 'password_errors' => []];

        if ($segments === ['password']) {
            if (!$request->isPost()) {
                throw new HttpException(405, 'error.method_not_allowed');
            }
            if (!$this->csrf->isValid($request)) {
                return $this->status($render('profile', 'profile', ['alert' => 'auth.form_expired'] + $context), 400);
            }
            $errors = self::matching($request);
            try {
                if ($errors === []) {
                    $this->users->changeOwnPassword($user, $request->postString('current_password'), $request->postString('password'));
                }
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
            if ($errors !== []) {
                return $this->status($render('profile', 'profile', ['password_errors' => $errors, 'alert' => 'admin.form.invalid'] + $context), 422);
            }
            $this->auth->refresh($request, $user);
            $this->flash->add(Flash::SUCCESS, 'users.password_changed');

            return Response::redirect($request->basePath . $this->access->path('profile'), 303);
        }
        if ($segments !== []) {
            throw HttpException::notFound();
        }
        if (!$request->isPost()) {
            return $render('profile', 'profile', $context);
        }
        if (!$this->csrf->isValid($request)) {
            return $this->status($render('profile', 'profile', ['alert' => 'auth.form_expired'] + $context), 400);
        }
        $name = trim($request->postString('name'));
        try {
            $this->users->updateProfile($user, $name);
        } catch (ValidationException $e) {
            return $this->status($render('profile', 'profile', ['errors' => $e->errors, 'name' => $name, 'alert' => 'admin.form.invalid'] + $context), 422);
        }
        $this->flash->add(Flash::SUCCESS, 'users.profile_saved');

        return Response::redirect($request->basePath . $this->access->path('profile'), 303);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function listing(Actor $actor, Closure $render): Response
    {
        return $render('users', 'user', [
            'title' => 'users.title',
            'users' => array_map(self::row(...), $this->users->all($actor)),
            'self' => $actor->id,
        ]);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function create(Request $request, Actor $actor, Closure $render): Response
    {
        $form = ['name' => '', 'email' => '', 'roles' => [], 'active' => true];
        $context = ['title' => 'users.new', 'account' => null, 'self' => false, 'password_errors' => [], 'roles' => $this->users->assignableRoles(), 'action' => $this->access->path('user/new')];
        if (!$request->isPost()) {
            return $render('user', 'user', $context + ['form' => $form, 'errors' => []]);
        }
        $form = self::read($request);
        if (!$this->csrf->isValid($request)) {
            return $this->status($render('user', 'user', $context + ['form' => $form, 'errors' => [], 'alert' => 'auth.form_expired']), 400);
        }
        $errors = self::matching($request);
        try {
            if ($errors === []) {
                $user = $this->users->create($actor, $form['name'], $form['email'], $request->postString('password'), $form['roles']);
                $this->flash->add(Flash::SUCCESS, new Message('users.created', ['name' => UserService::nameOf($user)]));

                return Response::redirect($request->basePath . $this->access->path('user'), 303);
            }
        } catch (ValidationException $e) {
            $errors = $e->errors;
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }

        return $this->status($render('user', 'user', $context + ['form' => $form, 'errors' => $errors, 'alert' => 'admin.form.invalid']), 422);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function edit(Request $request, Actor $actor, CampanellaObject $user, Closure $render): Response
    {
        $auth = $user->as(Authenticatable::class);
        $form = ['name' => (string) $user->get('title'), 'email' => (string) $user->get('email'), 'roles' => $auth->roles(), 'active' => $auth->isActive()];
        $context = [
            'title_text' => UserService::nameOf($user),
            'account' => $user,
            'self' => $actor->id === $user->id(),
            'roles' => $this->users->assignableRoles($user),
            'action' => $this->access->path('user/' . $user->id()),
            'password_errors' => [],
        ];
        if (!$request->isPost()) {
            return $render('user', 'user', $context + ['form' => $form, 'errors' => []]);
        }
        $form = self::read($request);
        if (!$this->csrf->isValid($request)) {
            return $this->status($render('user', 'user', $context + ['form' => $form, 'errors' => [], 'alert' => 'auth.form_expired']), 400);
        }
        try {
            $this->users->update($actor, $user, $form['name'], $form['email'], $form['roles'], $form['active']);
        } catch (ValidationException $e) {
            return $this->status($render('user', 'user', $context + ['form' => $form, 'errors' => $e->errors, 'alert' => 'admin.form.invalid']), 422);
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }
        $this->flash->add(Flash::SUCCESS, new Message('admin.form.saved', ['title' => UserService::nameOf($user)]));

        return Response::redirect($request->basePath . $this->access->path('user/' . $user->id()), 303);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function password(Request $request, Actor $actor, CampanellaObject $user, Closure $render): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $auth = $user->as(Authenticatable::class);
        $context = [
            'title_text' => UserService::nameOf($user),
            'account' => $user,
            'self' => $actor->id === $user->id(),
            'roles' => $this->users->assignableRoles($user),
            'action' => $this->access->path('user/' . $user->id()),
            'form' => ['name' => (string) $user->get('title'), 'email' => (string) $user->get('email'), 'roles' => $auth->roles(), 'active' => $auth->isActive()],
            'errors' => [],
        ];
        if (!$this->csrf->isValid($request)) {
            return $this->status($render('user', 'user', $context + ['password_errors' => [], 'alert' => 'auth.form_expired']), 400);
        }
        $errors = self::matching($request);
        try {
            if ($errors === []) {
                $this->users->setPassword($actor, $user, $request->postString('password'));
            }
        } catch (ValidationException $e) {
            $errors = $e->errors;
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }
        if ($errors !== []) {
            return $this->status($render('user', 'user', $context + ['password_errors' => $errors, 'alert' => 'admin.form.invalid']), 422);
        }
        // Changing one's own password here keeps this session too.
        if ($actor->id === $user->id()) {
            $this->auth->refresh($request, $user);
        }
        $this->flash->add(Flash::SUCCESS, new Message('users.password_set', ['name' => UserService::nameOf($user)]));

        return Response::redirect($request->basePath . $this->access->path('user/' . $user->id()), 303);
    }

    /**
     * What the pages show of a user (the e-mail address is a hidden field, not
     * readable from templates).
     *
     * @return array{id: int, name: string, email: string, roles: list<string>, active: bool}
     */
    private static function row(CampanellaObject $user): array
    {
        $auth = $user->as(Authenticatable::class);

        return [
            'id' => (int) $user->id(),
            'name' => UserService::nameOf($user),
            'email' => (string) $user->get('email'),
            'roles' => $auth->roles(),
            'active' => $auth->isActive(),
        ];
    }

    private function find(Actor $actor, string $id): CampanellaObject
    {
        $user = ctype_digit($id) ? $this->users->find($actor, (int) $id) : null;

        return $user ?? throw HttpException::notFound();
    }

    /** @return array{name: string, email: string, roles: list<string>, active: bool} */
    private static function read(Request $request): array
    {
        $roles = $request->post['roles'] ?? [];

        return [
            'name' => trim($request->postString('name')),
            'email' => trim($request->postString('email')),
            'roles' => array_values(array_filter(is_array($roles) ? $roles : [], is_string(...))),
            'active' => $request->postString('status') !== 'blocked',
        ];
    }

    /** @return array<string, Message> The two new passwords must match. */
    private static function matching(Request $request): array
    {
        return hash_equals($request->postString('password'), $request->postString('password_again'))
            ? []
            : ['password_again' => new Message('users.password_mismatch')];
    }

    private function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}

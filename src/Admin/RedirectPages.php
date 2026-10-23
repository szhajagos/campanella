<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Capability\Routable;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\Lists\ListPathCheck;
use Campanella\Security\Csrf;
use Campanella\Site\Redirects;
use Closure;

/**
 * The redirects in the admin (since 0.2.7), under /admin/system/redirects, for the
 * system roles: the list (the newest first, 50 a page), a new one from a path to a
 * path of the site, deleting one. The automatic ones (an object's old path) are
 * listed too, and can be deleted.
 */
final class RedirectPages
{
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly Redirects $redirects,
        private readonly ListPathCheck $paths,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/redirects
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        return match (true) {
            $segments === [] && !$request->isPost() => $this->page($request, $render, ['source' => '', 'target' => ''], []),
            $segments === ['new'] => $this->create($request, $render),
            count($segments) === 2 && ctype_digit($segments[0]) && $segments[1] === 'delete' => $this->delete($request, (int) $segments[0]),
            default => throw HttpException::notFound(),
        };
    }

    /**
     * @param Closure(string, string, array<string, mixed>): Response $render
     * @param array{source: string, target: string} $form
     * @param array<string, Message> $errors
     */
    private function page(Request $request, Closure $render, array $form, array $errors, int $status = 200): Response
    {
        $page = max(1, $request->queryInt('page', 1));
        $redirects = $this->redirects->page($page, self::PER_PAGE);
        $response = $render('redirects', 'redirects', [
            'title' => 'redirects.title',
            'redirects' => $redirects['items'],
            'total' => $redirects['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($redirects['total'] / self::PER_PAGE)),
            'form' => $form,
            'errors' => $errors,
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function create(Request $request, Closure $render): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $form = ['source' => trim($request->postString('source')), 'target' => trim($request->postString('target'))];
        if (!$this->csrf->isValid($request)) {
            return $this->page($request, $render, $form, ['form' => new Message('auth.form_expired')], 400);
        }
        $errors = [];
        $source = self::path($form['source']);
        $target = self::path($form['target']);
        if ($source === null) {
            $errors['source'] = new Message('redirects.invalid_path');
        } elseif ($this->redirects->exists($source)) {
            $errors['source'] = new Message('redirects.exists', ['path' => $source]);
        } elseif ($this->paths->isTaken($source, '')) {
            $errors['source'] = new Message('redirects.source_taken', ['path' => $source]);
        }
        if ($target === null) {
            $errors['target'] = new Message('redirects.invalid_path');
        } elseif ($target === $source) {
            $errors['target'] = new Message('redirects.same');
        }
        if ($errors !== [] || $source === null || $target === null) {
            return $this->page($request, $render, $form, $errors, 422);
        }
        $this->redirects->add($source, $target);
        $this->flash->add(Flash::SUCCESS, new Message('redirects.saved', ['source' => $source, 'target' => $target]));

        return Response::redirect($request->basePath . $this->access->path('system/redirects'), 303);
    }

    private function delete(Request $request, int $id): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');
        } elseif ($this->redirects->delete($id)) {
            $this->flash->add(Flash::SUCCESS, 'redirects.deleted');
        }

        return Response::redirect($request->basePath . $this->access->path('system/redirects'), 303);
    }

    /** A path of the site, normalized; null if it is not a usable one. */
    private static function path(string $path): ?string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || !Routable::isSafePath($path)) {
            return null;
        }
        $path = Routable::normalize($path);

        return mb_strlen($path, 'UTF-8') <= 255 ? $path : null;
    }
}

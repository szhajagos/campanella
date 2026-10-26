<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Downloadable;
use Campanella\Capability\Restricted;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Media\FileService;
use Campanella\Model\ObjectRepository;

/**
 * The downloads (since 0.3.2), at `paths.files` (`/files`): `/files/<id>/<name>`
 * sends the file of the object `<id>`, if the visitor may see it (otherwise 404,
 * as if it did not exist: a private file's existence is not told either). Any other
 * name redirects to the file's own (301), so a renamed download keeps working.
 *
 * Always a download, never a page: `Content-Disposition: attachment`, the type the
 * upload found, `nosniff`, and a Content-Security-Policy that allows nothing. A
 * public file may be cached (with its checksum as the ETag); a restricted one only by
 * the visitor's own browser, and search engines are asked not to index it.
 *
 * @internal
 */
final class FileController implements Controller
{
    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly AccessPolicy $policy,
        private readonly FileService $files,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (preg_match('#^([1-9]\d{0,18})(?:/([^/]*))?\z#', (string) ($route->params['subpath'] ?? ''), $m) !== 1) {
            throw HttpException::notFound();
        }
        $object = $this->repository->find((int) $m[1]);
        if ($object === null || !$object->has(Downloadable::class) || !$this->policy->allows($actor, Operation::View, $object)) {
            throw HttpException::notFound();
        }
        $download = $object->as(Downloadable::class);
        $url = $request->basePath . $this->files->url($object);
        if (rawurldecode($m[2] ?? '') !== $download->name()) {
            return Response::redirect($url, 301);
        }
        try {
            $path = $this->files->path($object);
        } catch (\InvalidArgumentException) {
            throw HttpException::notFound();
        }
        if (!is_file($path)) {
            error_log("Campanella: the file of #{$object->id()} ({$download->path()}) is missing.");
            throw HttpException::notFound();
        }

        $public = !$object->has(Restricted::class) || $object->as(Restricted::class)->isPublic();
        $etag = '"' . $download->hash() . '"';
        $headers = [
            'Content-Type' => $download->mimeType() . (str_starts_with($download->mimeType(), 'text/') ? '; charset=utf-8' : ''),
            'Content-Disposition' => 'attachment; filename="' . $download->name() . '"',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT',
            // Public: proxies may keep it for an hour; restricted: only the visitor's browser, checking each time.
            'Cache-Control' => $public ? 'public, max-age=3600' : 'private, no-cache',
        ];
        if (!$public) {
            $headers['X-Robots-Tag'] = 'noindex';
        }
        $match = array_map('trim', explode(',', (string) ($request->headers['if-none-match'] ?? '')));
        if (in_array($etag, $match, true) || in_array('W/' . $etag, $match, true)) {
            return new Response('', 304, array_diff_key($headers, ['Content-Type' => true, 'Content-Disposition' => true]));
        }
        if ($request->method === 'HEAD') {
            return new Response('', 200, $headers + ['Content-Length' => (string) filesize($path)]);
        }

        return Response::file($path, $headers);
    }
}

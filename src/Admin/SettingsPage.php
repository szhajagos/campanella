<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Capability\MediaFile;
use Campanella\Http\Flash;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Media\MediaStorage;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Security\Csrf;
use Campanella\Site\SiteSettings;
use Closure;

/**
 * The admin's Settings page (/admin/system/settings, for the system roles; since
 * 0.1.1): the site's name, slogan, default description, address, share image and
 * whether search engines may index it. The rules are SiteSettings'.
 */
final class SettingsPage
{
    /** The most images offered as the share image (the newest ones; an image picker comes later). */
    public const int MAX_IMAGES = 100;

    public function __construct(
        private readonly SiteSettings $site,
        private readonly QueryEngine $queries,
        private readonly MediaStorage $media,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render template, active menu item, context */
    public function handle(Request $request, Actor $actor, Closure $render): Response
    {
        $values = $this->site->values();
        $form = [
            'name' => (string) $values['name'],
            'slogan' => (string) $values['slogan'],
            'description' => (string) $values['description'],
            'url' => (string) $values['url'],
            'share_image' => (string) ($values['share_image'] ?? ''),
            'indexing' => (bool) $values['indexing'],
        ];
        $context = [
            'title' => 'settings.title',
            'origin' => SiteSettings::originOf($request),
            'images' => $this->images($actor, $form['share_image']),
            'max' => SiteSettings::MAX_LENGTH,
            'errors' => [],
        ];
        if (!$request->isPost()) {
            return $render('settings', 'settings', $context + ['form' => $form]);
        }

        $input = [];
        foreach (['name', 'slogan', 'description', 'url', 'share_image'] as $key) {
            $input[$key] = $request->postString($key);
        }
        $input['indexing'] = $request->postString('indexing') === '1' ? '1' : '0';
        $form = ['indexing' => $input['indexing'] === '1'] + $input;
        if (!$this->csrf->isValid($request)) {
            return self::status($render('settings', 'settings', $context + ['form' => $form, 'alert' => 'auth.form_expired']), 400);
        }
        try {
            $this->site->save($input);
        } catch (ValidationException $e) {
            return self::status($render('settings', 'settings', ['errors' => $e->errors, 'alert' => 'admin.form.invalid', 'form' => $form] + $context), 422);
        }
        $this->flash->add(Flash::SUCCESS, 'settings.saved');

        return Response::redirect($request->basePath . $this->access->path('system/settings'), 303);
    }

    /**
     * The images offered: the newest ones, and the current one if it is older.
     *
     * @return list<array{id: int, title: string, url: string, width: ?int, height: ?int}>
     */
    private function images(Actor $actor, string $current): array
    {
        $images = $this->queries->execute(
            Query::objects()->having(MediaFile::class)->orderBy('created', 'DESC')->orderBy('id', 'DESC')->limit(self::MAX_IMAGES),
            $actor,
        )->items;
        if ($current !== '' && ctype_digit($current) && array_filter($images, static fn ($i): bool => $i->id() === (int) $current) === []) {
            $image = $this->queries->first(Query::objects()->having(MediaFile::class)->where('id', '=', (int) $current), $actor);
            if ($image !== null) {
                $images[] = $image;
            }
        }

        return array_map(fn ($image): array => [
            'id' => (int) $image->id(),
            'title' => (string) $image->get('title'),
            'url' => $this->media->url((string) $image->get('file_path')),
            'width' => is_int($image->get('width')) ? $image->get('width') : null,
            'height' => is_int($image->get('height')) ? $image->get('height') : null,
        ], $images);
    }

    private static function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}

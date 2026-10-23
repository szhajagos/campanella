<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Transfer\ImportItem;
use Campanella\Transfer\ImportPlan;
use Campanella\Transfer\ModelExport;
use Campanella\Transfer\ModelFile;
use Campanella\Transfer\ModelImport;
use Campanella\Security\Csrf;
use Closure;

/**
 * The content model's export and import in the admin (since 0.2.6), under
 * /admin/system/model, for the system roles:
 *
 * - `/admin/system/model`: the page: the download, and the form to upload a file;
 * - `/admin/system/model/export`: the file (`campanella-model.json`);
 * - POST `/admin/system/model/import`: the uploaded file's plan (nothing changes);
 * - POST `/admin/system/model/apply`: the confirmed import.
 *
 * The uploaded file is not stored: the plan's page sends it back with the
 * confirmation, and it is checked again in full then. It is applied only if the
 * plan is still the same (ImportPlan::$fingerprint): if the site's model changed
 * in the meantime, the new plan is shown instead.
 */
final class ModelPage
{
    public function __construct(
        private readonly ModelExport $export,
        private readonly ModelImport $import,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/model
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        return match ($segments) {
            [] => $this->page($render),
            ['export'] => $this->download(),
            ['import'] => $this->upload($request, $render),
            ['apply'] => $this->apply($request, $render),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function page(Closure $render, ?string $alert = null, int $status = 200): Response
    {
        $model = $this->export->model();
        $response = $render('model', 'model', [
            'title' => 'model.title',
            'blueprints' => count($model['blueprints']),
            'lists' => count($model['lists']),
            'max_bytes' => ModelFile::MAX_BYTES,
            'alert_text' => $alert,
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    private function download(): Response
    {
        return new Response($this->export->json(), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ModelFile::FILE_NAME . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function upload(Request $request, Closure $render): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf->isValid($request)) {
            return $this->page($render, $this->translator->translate('auth.form_expired'), 400);
        }
        $file = $request->file('file');
        if ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
            return $this->page($render, $this->translator->translate('model.no_file'), 422);
        }
        if ($file->isTooLarge() || $file->size > ModelFile::MAX_BYTES) {
            return $this->page($render, $this->translator->translate('transfer.error.too_large'), 422);
        }
        $json = $file->isOk() && is_file($file->path) && filesize($file->path) <= ModelFile::MAX_BYTES ? file_get_contents($file->path) : false;
        if ($json === false) {
            return $this->page($render, $this->translator->translate('model.upload_failed'), 422);
        }

        return $this->planned($json, $render);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function apply(Request $request, Closure $render): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf->isValid($request)) {
            return $this->page($render, $this->translator->translate('auth.form_expired'), 400);
        }
        $encoded = $request->postString('model');
        $json = strlen($encoded) <= (int) ceil(ModelFile::MAX_BYTES / 3) * 4 ? base64_decode($encoded, true) : false;
        if ($json === false || $json === '') {
            return $this->page($render, $this->translator->translate('model.upload_failed'), 422);
        }
        try {
            $plan = $this->import->plan(ModelFile::decode($json));
        } catch (\InvalidArgumentException $e) {
            return $this->page($render, $this->translator->translate('transfer.error.file', ['reason' => $e->getMessage()]), 422);
        }
        if (!hash_equals($plan->fingerprint, $request->postString('fingerprint'))) {
            return $this->planView($plan, $json, $render, $this->translator->translate('model.changed_since'), 409);
        }
        if ($plan->hasErrors() || !$plan->hasChanges()) {
            return $this->planView($plan, $json, $render, null, $plan->hasErrors() ? 422 : 200);
        }
        try {
            $backup = $this->import->apply($plan);
        } catch (\RuntimeException $e) {
            return $this->planView($plan, $json, $render, $this->translator->translate('transfer.failed', ['error' => $e->getMessage()]), 422);
        }
        if ($backup !== null) {
            $this->flash->add(Flash::INFO, new Message('blueprints.backup_made', ['file' => basename($backup)]));
        }
        $this->flash->add(Flash::SUCCESS, new Message('transfer.done', ['blueprints' => count($plan->blueprints), 'lists' => count($plan->lists)]));

        return Response::redirect($request->basePath . $this->access->path('system/model'), 303);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function planned(string $json, Closure $render): Response
    {
        try {
            $plan = $this->import->plan(ModelFile::decode($json));
        } catch (\InvalidArgumentException $e) {
            return $this->page($render, $this->translator->translate('transfer.error.file', ['reason' => $e->getMessage()]), 422);
        }

        return $this->planView($plan, $json, $render, null, $plan->hasErrors() ? 422 : 200);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function planView(ImportPlan $plan, string $json, Closure $render, ?string $alert, int $status): Response
    {
        $t = $this->translator;
        $items = array_map(static fn (ImportItem $item): array => [
            'kind' => $item->kind,
            'name' => $item->name,
            'label' => $item->label,
            'action' => $item->action,
            'changes' => array_map(static fn (Message $m): string => $m->translate($t), $item->changes),
            'warnings' => array_map(static fn (Message $m): string => $m->translate($t), $item->warnings),
            'error' => $item->error?->translate($t),
        ], $plan->items);
        $response = $render('model_plan', 'model', [
            'title' => 'model.plan',
            'items' => $items,
            'errors' => array_map(static fn (Message $m): string => $m->translate($t), $plan->errors),
            'database' => array_map(static fn (Message $m): string => $m->translate($t), $plan->database),
            'backup' => $plan->backup,
            'has_errors' => $plan->hasErrors(),
            'has_changes' => $plan->hasChanges(),
            'counts' => $plan->counts(),
            'model' => base64_encode($json),
            'fingerprint' => $plan->fingerprint,
            'alert_text' => $alert,
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }
}

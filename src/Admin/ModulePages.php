<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\Module\ModuleLoader;
use Campanella\Module\ModuleManager;
use Campanella\Module\ModuleManifest;
use Campanella\Module\ModuleRegistry;
use Campanella\Module\ModuleState;
use Campanella\Module\ModuleTrial;
use Campanella\Security\Csrf;
use Closure;

/**
 * The modules in the admin (since 0.4.6), under /admin/system/modules, for the system roles:
 *
 *   /admin/system/modules                every module of the modules folder, its version, the
 *                                        Campanella versions and the modules it needs, where
 *                                        it stands (ModuleState) and why
 *   /admin/system/modules/<name>/enable  the trial (ModuleTrial) and what it brings, the
 *                                        warning that a module is code with every right; POST
 *                                        tries it again and turns it on (ModuleManager)
 *   POST /admin/system/modules/<name>/disable   turns it off (not while it is needed or used)
 *   /admin/system/modules/<name>/uninstall      removes its data for good (since 0.4.7): only
 *                                        while it is off, its name typed to confirm, after a backup
 */
final class ModulePages
{
    public const string PATH = 'system/modules';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ?ModuleLoader $loader = null,
        private readonly ?ModuleTrial $trial = null,
        private readonly ?ModuleManager $manager = null,
        private readonly ?Csrf $csrf = null,
        private readonly ?Flash $flash = null,
        private readonly ?AdminAccess $access = null,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/modules
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        if ($segments === [] && !$request->isPost()) {
            return $this->listing($render);
        }
        $state = count($segments) === 2 && preg_match(ModuleManifest::NAME_PATTERN, $segments[0]) === 1 ? $this->modules->get($segments[0]) : null;
        if ($state === null || $this->manager === null) {
            throw HttpException::notFound();
        }

        return match ($segments[1]) {
            'enable' => $this->enable($request, $state, $render),
            'disable' => $this->disable($request, $state),
            'uninstall' => $this->uninstall($request, $state, $render),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function listing(Closure $render): Response
    {
        $failures = $this->loader?->failures() ?? [];

        return $render('modules', 'modules', [
            'title' => 'modules.title',
            'modules' => array_values(array_map(fn (ModuleState $state): array => $this->view($state) + [
                'failure' => $failures[$state->name] ?? null,
                'can_uninstall' => !$state->enabled && $state->manifest !== null && $this->manager !== null && $this->manager->canUninstall($state->manifest),
            ], $this->modules->all())),
            'directory' => basename($this->modules->directory()),
            'can_change' => $this->manager !== null,
        ]);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function enable(Request $request, ModuleState $state, Closure $render): Response
    {
        \assert($this->manager !== null && $this->trial !== null);
        if ($request->isPost()) {
            if (!$this->csrf?->isValid($request)) {
                $this->flash?->add(Flash::DANGER, 'auth.form_expired');

                return $this->back($request);
            }
            $problems = $this->manager->enable($state->name);
            if ($problems === []) {
                $this->flash?->add(Flash::SUCCESS, new Message('modules.enabled', ['label' => $state->manifest->label ?? $state->name]));

                return $this->back($request);
            }
            $trial = ['problems' => $problems, 'brings' => []];
        } else {
            $trial = $state->enabled && $state->status !== ModuleState::BROKEN
                ? ['problems' => [new Message('modules.already_on')], 'brings' => []]
                : $this->trial->run($state->name);
        }
        $response = $render('module_enable', 'modules', [
            'title' => 'modules.enable_title',
            'module' => $this->view($state),
            'problems' => $trial['problems'],
            'brings' => $trial['brings'],
        ]);

        return $trial['problems'] === [] ? $response : new Response($response->body, 422, $response->headers);
    }

    private function disable(Request $request, ModuleState $state): Response
    {
        \assert($this->manager !== null);
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf?->isValid($request)) {
            $this->flash?->add(Flash::DANGER, 'auth.form_expired');
        } else {
            $problems = $this->manager->disable($state->name);
            if ($problems === []) {
                $this->flash?->add(Flash::SUCCESS, new Message('modules.disabled', ['label' => $state->manifest->label ?? $state->name]));
            }
            foreach ($problems as $problem) {
                $this->flash?->add(Flash::DANGER, $problem);
            }
        }

        return $this->back($request);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function uninstall(Request $request, ModuleState $state, Closure $render): Response
    {
        \assert($this->manager !== null);
        $problem = null;
        if ($state->manifest === null || !$this->manager->canUninstall($state->manifest)) {
            throw HttpException::notFound();
        }
        if ($request->isPost()) {
            if (!$this->csrf?->isValid($request)) {
                $problem = new Message('auth.form_expired');
            } elseif (trim($request->postString('confirm')) !== $state->name) {
                $problem = new Message('modules.uninstall_confirm_wrong', ['name' => $state->name]);
            } else {
                $problem = $this->manager->uninstall($state->name);
                if ($problem === null) {
                    $this->flash?->add(Flash::SUCCESS, new Message('modules.uninstalled', ['label' => $state->manifest->label]));

                    return $this->back($request);
                }
            }
        }
        $response = $render('module_uninstall', 'modules', [
            'title' => 'modules.uninstall_title',
            'module' => $this->view($state),
            'problem' => $problem ?? ($state->enabled ? new Message('modules.uninstall_on') : null),
        ]);

        return $problem === null ? $response : new Response($response->body, 422, $response->headers);
    }

    private function back(Request $request): Response
    {
        return Response::redirect($request->basePath . ($this->access?->path(self::PATH) ?? '/admin/' . self::PATH), 303);
    }

    /** @return array<string, mixed> */
    private function view(ModuleState $state): array
    {
        return [
            'name' => $state->name,
            'label' => $state->manifest->label ?? $state->name,
            'description' => $state->manifest->description ?? '',
            'version' => $state->manifest?->version,
            'campanella' => $state->manifest?->campanella,
            'requires' => $state->manifest->requires ?? [],
            'status' => $state->status,
            'enabled' => $state->enabled,
            'problems' => $state->problems,
        ];
    }
}

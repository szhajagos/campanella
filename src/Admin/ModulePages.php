<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Module\ModuleRegistry;
use Campanella\Module\ModuleState;
use Closure;

/**
 * The modules in the admin (since 0.4.6), under /admin/system/modules, for the system roles:
 * every module of the modules folder, its version, the Campanella versions and the modules it
 * needs, and where it stands (ModuleState), with the reasons when it cannot be used.
 */
final class ModulePages
{
    public const string PATH = 'system/modules';

    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    /**
     * @param list<string> $segments The path after /admin/system/modules
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        if ($segments !== [] || $request->isPost()) {
            throw HttpException::notFound();
        }

        return $render('modules', 'modules', [
            'title' => 'modules.title',
            'modules' => array_values(array_map(static fn (ModuleState $state): array => [
                'name' => $state->name,
                'label' => $state->manifest->label ?? $state->name,
                'description' => $state->manifest->description ?? '',
                'version' => $state->manifest?->version,
                'campanella' => $state->manifest?->campanella,
                'requires' => $state->manifest->requires ?? [],
                'status' => $state->status,
                'problems' => $state->problems,
            ], $this->modules->all())),
            'directory' => basename($this->modules->directory()),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Import\Importer;
use Campanella\Import\ImporterDefinition;
use Campanella\Import\ImportRun;
use Campanella\Import\ImportTrial;
use Campanella\Model\BlueprintRegistry;
use Campanella\Net\SafeFetcher;
use Campanella\Schedule\Scheduler;
use Closure;

/**
 * The sources in the admin, /admin/module/feeds (the system roles):
 *
 *   /admin/module/feeds                 the sources, where each stands
 *   /admin/module/feeds/new, /<id>      a source's form (its importer, address, interval)
 *   POST /admin/module/feeds/<id>/try   fetched now, what its first items would become
 *   POST /admin/module/feeds/<id>/fetch fetched and imported in the next cron:run
 *   POST /admin/module/feeds/<id>/delete
 *
 * The admin checks the CSRF token of a POST before handle().
 */
final class Admin implements \Campanella\Module\AdminPage
{
    public function __construct(
        private readonly SourceStore $sources,
        private readonly Feeds $feeds,
        private readonly Importer $importer,
        private readonly SafeFetcher $fetcher,
        private readonly Scheduler $scheduler,
        private readonly Translator $translator,
        private readonly BlueprintRegistry $blueprints,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        if ($segments === []) {
            return $request->isPost() ? throw new HttpException(405, 'error.method_not_allowed') : $this->listing($render);
        }
        if ($segments === ['new']) {
            return $this->form($request, null, $render);
        }
        $source = preg_match('/^[1-9][0-9]{0,9}$/D', $segments[0]) === 1 ? $this->sources->find((int) $segments[0]) : null;
        if ($source === null || count($segments) > 2) {
            throw HttpException::notFound();
        }
        if (count($segments) === 1) {
            return $this->form($request, $source, $render);
        }
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }

        return match ($segments[1]) {
            'try' => $this->trial($source, $render),
            'fetch' => $this->fetchNext($request, $source),
            'delete' => $this->delete($request, $source),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, array<string, mixed>): Response $render */
    private function listing(Closure $render): Response
    {
        [$importers] = $this->importer->all();

        return $render('admin.html.twig', [
            'title' => 'feeds.title',
            'sources' => array_map(fn (Source $s): array => [
                'source' => $s,
                'host' => $s->host(),
                'importer' => $importers[$s->importer] ?? null,
                'run' => $s->runId === null ? null : $this->importer->run($s->runId),
                'drafts' => $s->kind === 'feed' && ($importers[$s->importer] ?? null)?->missingItems === 'draft',
            ], $this->sources->all()),
            'has_importers' => $importers !== [],
            'cron_stale' => $this->scheduler->isStale(),
            'curl' => \Campanella\Net\CurlTransport::available(),
        ]);
    }

    /** @param Closure(string, array<string, mixed>): Response $render */
    private function form(Request $request, ?Source $source, Closure $render): Response
    {
        $values = $source === null
            ? ['importer' => '', 'url' => '', 'kind' => 'file', 'every' => '60', 'enabled' => true]
            : ['importer' => $source->importer, 'url' => $source->url, 'kind' => $source->kind, 'every' => (string) $source->everyMinutes, 'enabled' => $source->enabled];
        $errors = [];
        if ($request->isPost()) {
            $values = [
                'importer' => trim($request->postString('importer')),
                'url' => trim($request->postString('url')),
                'kind' => $request->postString('kind'),
                'every' => $request->postString('every'),
                'enabled' => $request->postString('enabled') === '1',
            ];
            $errors = $this->problems($values, $source);
            if ($errors === []) {
                $every = (int) $values['every'];
                if ($source === null) {
                    $id = $this->sources->create($values['importer'], $values['url'], $values['kind'], $every, $values['enabled']);
                    $this->flash->add(Flash::SUCCESS, new Message('feeds.created', ['importer' => $values['importer']]));
                } else {
                    $this->sources->update($source, $values['url'], $values['kind'], $every, $values['enabled']);
                    $id = $source->id;
                    $this->flash->add(Flash::SUCCESS, new Message('feeds.saved', ['importer' => $source->importer]));
                }

                return $this->back($request, (string) $id);
            }
        }
        $taken = array_flip(array_map(static fn (Source $s): string => $s->importer, $this->sources->all()));
        $importers = array_filter($this->importer->all()[0], static fn (ImporterDefinition $d): bool => !isset($taken[$d->name]) || $d->name === $source?->importer);
        $response = $render('form.html.twig', [
            'title' => $source === null ? 'feeds.new' : 'feeds.edit',
            'source' => $source,
            'values' => $values,
            'errors' => $errors,
            'importers' => $importers,
            'every' => Source::EVERY,
            'run' => $source?->runId === null ? null : $this->importer->run($source->runId),
            'kinds' => Source::KINDS,
            'keys' => FeedReader::KEYS,
            'drafts' => array_keys(array_filter($importers, static fn (ImporterDefinition $d): bool => $d->missingItems === 'draft')),
        ]);

        return $errors === [] ? $response : new Response($response->body, 422, $response->headers);
    }

    /**
     * @param array{importer: string, url: string, kind: string, every: string, enabled: bool} $values
     * @return array<string, Message>
     */
    private function problems(array $values, ?Source $source): array
    {
        $errors = [];
        if ($source === null) {
            if ($this->importer->find($values['importer']) === null) {
                $errors['importer'] = new Message('feeds.error.choose_importer');
            } elseif ($this->sources->findByImporter($values['importer']) !== null) {
                $errors['importer'] = new Message('feeds.error.importer_taken');
            }
        }
        $definition = $this->importer->find($source->importer ?? $values['importer']);
        if (!in_array($values['kind'], Source::KINDS, true)) {
            $errors['kind'] = new Message('feeds.error.kind');
        } elseif ($definition !== null && !isset($errors['importer'])) {
            $problem = Feeds::importerProblem($values['kind'], $definition);
            if ($problem !== null) {
                $errors['kind'] = $problem;
            }
        }
        $problem = $this->fetcher->problem($values['url']);
        if ($problem !== null) {
            $errors['url'] = $problem;
        }
        if (!in_array((int) $values['every'], Source::EVERY, true)) {
            $errors['every'] = new Message('feeds.error.every');
        }

        return $errors;
    }

    /** @param Closure(string, array<string, mixed>): Response $render */
    private function trial(Source $source, Closure $render): Response
    {
        $trial = $this->feeds->trial($source);
        $definition = $this->importer->find($source->importer);
        $response = $render('try.html.twig', [
            'title' => 'feeds.trial',
            'source' => $source,
            'problem' => $trial instanceof Message ? $trial : null,
            'trial' => $trial instanceof ImportTrial && $definition !== null ? $this->trialView($trial, $definition) : null,
        ]);

        return $trial instanceof Message ? new Response($response->body, 422, $response->headers) : $response;
    }

    private function fetchNext(Request $request, Source $source): Response
    {
        $this->sources->makeDue($source);
        $this->scheduler->request('feeds:fetch');
        $this->flash->add(Flash::SUCCESS, new Message('feeds.fetch_requested', ['importer' => $source->importer]));

        return $this->back($request, '');
    }

    private function delete(Request $request, Source $source): Response
    {
        $this->sources->delete($source);
        $this->flash->add(Flash::SUCCESS, new Message('feeds.deleted', ['importer' => $source->importer]));

        return $this->back($request, '');
    }

    /** @return array<string, mixed> */
    private function trialView(ImportTrial $trial, ImporterDefinition $definition): array
    {
        $labels = [];
        $blueprint = $this->blueprints->find($definition->blueprint);
        if ($blueprint !== null) {
            foreach (ImporterDefinition::targets($blueprint) as $name => $target) {
                $labels[$name] = $this->translator->translate($target->label !== '' ? $target->label : $name);
            }
        }

        return [
            'total' => $trial->total,
            'columns' => $trial->columns,
            'missing' => $trial->missingSources,
            'rows' => array_map(fn (array $row): array => [
                'row' => $row['row'],
                'key' => $row['key'],
                'action' => $row['action'],
                'values' => array_combine(array_map(static fn (string $n): string => $labels[$n] ?? $n, array_keys($row['values'])), array_values($row['values'])),
                'error' => $row['error'] === null ? null : ($row['field'] !== '' ? $row['field'] . ': ' : '') . ImportRun::text($row['error'], $this->translator),
            ], $trial->rows),
            'shown' => count($trial->rows),
        ];
    }

    private function back(Request $request, string $to): Response
    {
        return Response::redirect($request->basePath . $this->access->path('module/feeds' . ($to !== '' ? '/' . $to : '')), 303);
    }
}

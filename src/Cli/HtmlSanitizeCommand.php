<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Textual;
use Campanella\Capability\TextFormat;
use Campanella\Core\Container;
use Campanella\Html\HtmlSanitizer;
use Campanella\I18n\Translator;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

/**
 * `php bin/campanella html:sanitize [--dry-run]`: filters the HTML texts saved
 * earlier (before 0.0.5, or under a wider allowlist) with the current
 * allowlist. Texts that change are saved again; `--dry-run` only lists them.
 * A text that cannot be filtered or saved is reported and skipped (exit code 1),
 * the others are still processed.
 */
final class HtmlSanitizeCommand implements Command
{
    private const int PAGE_SIZE = 100;

    #[\Override]
    public function name(): string
    {
        return 'html:sanitize';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.html_sanitize.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $dryRun = Args::parse($args)->flag('dry-run');
        $sanitizer = $container->get(HtmlSanitizer::class);
        $repository = $container->get(ObjectRepository::class);
        $queries = $container->get(QueryEngine::class);

        $checked = 0;
        $changed = 0;
        $failed = 0;
        for ($page = 1; ; $page++) {
            $objects = $queries->execute(
                Query::objects()->having('textual')->orderBy('id')->page($page, self::PAGE_SIZE),
                Actor::system(),
            );
            foreach ($objects as $object) {
                $textual = $object->as(Textual::class);
                if ($textual->format() !== TextFormat::Html) {
                    continue;
                }
                $checked++;
                $label = sprintf('#%d %s', (int) $object->id(), $object->hasField('title') ? (string) $object->get('title') : '');
                $problem = $sanitizer->problem($textual->body());
                if ($problem !== null) {
                    $failed++;
                    $output->error($t->translate('cli.html_sanitize.skipped', ['object' => $label, 'reason' => $problem->translate($t)]));
                    continue;
                }
                if ($sanitizer->sanitize($textual->body()) === $textual->body()) {
                    continue;
                }
                if (!$dryRun) {
                    try {
                        // Saving filters the text (ObjectRepository::save()).
                        $repository->save($object);
                    } catch (ValidationException $e) {
                        // Another field of the object is invalid: report it and carry on with the rest.
                        $failed++;
                        $output->error($t->translate('cli.html_sanitize.skipped', [
                            'object' => $label,
                            'reason' => implode('; ', $e->messages($t)),
                        ]));
                        continue;
                    }
                }
                $changed++;
                $output->line('  ' . $label);
            }
            if (count($objects) < self::PAGE_SIZE) {
                break;
            }
        }

        $output->success($t->translate($dryRun ? 'cli.html_sanitize.dry_run' : 'cli.html_sanitize.done', [
            'checked' => $checked,
            'changed' => $changed,
        ]));

        return $failed > 0 ? 1 : 0;
    }
}

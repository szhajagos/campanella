<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Capability\MediaFile;
use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Media\MediaService;

/**
 * `php bin/campanella media:variants [--all]`: makes the smaller copies of the
 * images that have none yet (uploaded before 0.1.2); `--all`: of every image
 * again (e.g. after changing the media.variants setting). Since 0.1.2.
 */
final class MediaVariantsCommand implements Command
{
    /** Images read at once. */
    private const int BATCH = 100;

    #[\Override]
    public function name(): string
    {
        return 'media:variants';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.media_variants.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $media = $container->get(MediaService::class);
        $all = Args::parse($args)->flag('all');
        $done = 0;
        $after = 0;
        while (true) {
            // By ID: an image left without copies (e.g. too large now) is not met again.
            $images = $all ? $media->images($after, self::BATCH) : $media->withoutVariants(self::BATCH, $after);
            if ($images === []) {
                break;
            }
            foreach ($images as $image) {
                $widths = $media->makeVariants($image);
                $output->line(sprintf('  #%-6d %-40s %s', (int) $image->id(), $image->as(MediaFile::class)->path(), $widths === [] ? '–' : implode(', ', $widths)));
                $after = (int) $image->id();
                $done++;
            }
        }
        $output->success($t->translate('cli.media_variants.done', ['count' => $done]));

        return 0;
    }
}

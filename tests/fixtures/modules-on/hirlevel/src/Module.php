<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Event\ObjectPublished;
use Campanella\Module\ModuleContext;

/** A test module that brings one of everything a module may (since 0.4.6). */
final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        $context->capability(Feliratkozhato::class)
            ->display(DobozDisplay::class)
            ->tokens(Tokenek::class)
            ->on(ObjectPublished::class, Ertesites::class)
            ->fieldType(TelefonType::class)
            ->fieldType(SzinType::class)
            ->command(new Parancs())
            ->route('/hirlevel', Oldal::class)
            ->adminPage(Admin::class, 'hirlevel.admin', 'audience', editors: true);
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Authenticatable;
use Campanella\Core\Container;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

final class UserListCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'user:list';
    }

    #[\Override]
    public function description(): string
    {
        return 'Felhasználók listája';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $users = $container->get(QueryEngine::class)->execute(
            Query::objects()->having('authenticatable')->orderBy('email'),
            Actor::system(),
        );
        if ($users->isEmpty()) {
            $output->line('Még nincs felhasználó. Létrehozás: php bin/campanella user:create <e-mail> --role=administrator');

            return 0;
        }
        foreach ($users as $user) {
            $auth = $user->as(Authenticatable::class);
            $output->line(sprintf(
                '  #%-4d %-32s %-24s %-8s %s',
                $user->id(),
                $user->get('email'),
                $user->get('title'),
                $auth->isActive() ? 'aktív' : 'letiltva',
                implode(', ', $auth->roles()),
            ));
        }

        return 0;
    }
}

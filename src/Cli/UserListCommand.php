<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Authenticatable;
use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

/** `php bin/campanella user:list [--role=editor]`: the users, or those with the role. */
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
        return 'cli.user_list.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $query = Query::objects()->having('authenticatable')->orderBy('email');
        $role = Args::parse($args)->option('role');
        if ($role !== null && $role !== '') {
            $query = $query->where('roles', '=', $role); // a multi-valued field: any of the roles (since 0.0.6)
        }
        $users = $container->get(QueryEngine::class)->execute($query, Actor::system());
        if ($users->isEmpty()) {
            $output->line($t->translate('cli.user_list.empty'));

            return 0;
        }
        foreach ($users as $user) {
            $auth = $user->as(Authenticatable::class);
            $output->line(sprintf(
                '  #%-4d %-32s %-24s %-8s %s',
                $user->id(),
                $user->get('email'),
                $user->get('title'),
                $t->translate($auth->isActive() ? 'cli.user.active' : 'cli.user.blocked'),
                implode(', ', $auth->roles()),
            ));
        }

        return 0;
    }
}

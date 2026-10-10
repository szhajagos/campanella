<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Auth\PasswordReset;
use Campanella\Auth\SessionRegistry;
use Campanella\Database\Connection;
use Campanella\Event\EventDispatcher;
use Campanella\Event\PasswordChanged;
use Campanella\Capability\Authenticatable;
use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;

/**
 * Sets a password (e.g. a forgotten password), and blocks or re-activates
 * the account.
 *
 *   php bin/campanella user:password anna@example.hu
 *   php bin/campanella user:password anna@example.hu --block
 *   php bin/campanella user:password anna@example.hu --activate
 */
final class UserPasswordCommand implements Command
{
    public function __construct(private readonly Input $input = new Input())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'user:password';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.user_password.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $args = Args::parse($args);
        $email = (string) $args->argument(0);
        $user = $email === '' ? null : $container->get(AuthService::class)->findUserByEmail($email);
        if ($user === null) {
            $output->error($t->translate('cli.user.not_found', ['email' => $email]));

            return 1;
        }
        $auth = $user->as(Authenticatable::class);
        $repository = $container->get(ObjectRepository::class);

        if ($args->flag('block') || $args->flag('activate')) {
            $args->flag('block') ? $auth->block() : $auth->activate();
            $repository->save($user);
            if (!$auth->isActive()) {
                // Its logins and its forgotten password link end now (since 0.1.4).
                $container->get(SessionRegistry::class)->endAll((int) $user->id());
                PasswordReset::forgetIn($container->get(Connection::class), (int) $user->id());
            }
            $output->success(sprintf('%s: %s', $user->get('email'), $t->translate($auth->isActive() ? 'cli.user.active' : 'cli.user.blocked')));

            return 0;
        }

        $password = PasswordPrompt::ask($this->input, $output, $t);
        if ($password === null) {
            return 1;
        }
        try {
            $auth->setPassword($password);
            $repository->save($user);
            // As when an administrator sets it: every login of the user ends, and any
            // forgotten password link is voided (the Kernel's listener; since 0.1.4).
            $container->get(EventDispatcher::class)->dispatch(new PasswordChanged($user, true, Actor::system()));
        } catch (ValidationException $e) {
            foreach ($e->messages($t) as $field => $message) {
                $output->error("{$field}: {$message}");
            }

            return 1;
        }
        $output->success($t->translate('cli.user_password.done', ['email' => (string) $user->get('email')]));

        return 0;
    }
}

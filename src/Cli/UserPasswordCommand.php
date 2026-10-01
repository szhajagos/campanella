<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Auth\AuthService;
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

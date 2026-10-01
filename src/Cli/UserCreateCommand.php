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
 * Creates a new user. The command prompts for the password (it is not shown
 * on screen and does not end up in the shell history).
 *
 *   php bin/campanella user:create anna@example.hu --name="Kovács Anna" --role=administrator
 */
final class UserCreateCommand implements Command
{
    public function __construct(private readonly Input $input = new Input())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'user:create';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.user_create.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $args = Args::parse($args);
        $email = (string) $args->argument(0);
        if ($email === '') {
            $output->error($t->translate('cli.user_create.missing_email'));

            return 1;
        }
        if ($container->get(AuthService::class)->findUserByEmail($email) !== null) {
            $output->error($t->translate('cli.user_create.exists', ['email' => $email]));

            return 1;
        }

        $password = PasswordPrompt::ask($this->input, $output, $t);
        if ($password === null) {
            return 1;
        }

        $roles = array_values(array_filter(array_map('trim', explode(',', (string) $args->option('role', '')))));
        $name = (string) $args->option('name', strstr($email, '@', true) ?: $email);

        $repository = $container->get(ObjectRepository::class);
        try {
            $user = $repository->create('user', ['title' => $name, 'email' => $email]);
            $auth = $user->as(Authenticatable::class);
            $auth->setPassword($password);
            $auth->setRoles($roles);
            $repository->save($user);
        } catch (ValidationException $e) {
            foreach ($e->messages($t) as $field => $message) {
                $output->error("{$field}: {$message}");
            }

            return 1;
        }
        unset($password);

        $output->success($t->translate('cli.user_create.created', [
            'id' => (int) $user->id(),
            'name' => $name,
            'email' => (string) $user->get('email'),
        ]) . ($roles === [] ? '' : ' – ' . $t->translate('cli.user.roles', ['roles' => implode(', ', $roles)])));

        return 0;
    }
}

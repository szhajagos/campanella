<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Auth\AuthService;
use Campanella\Capability\Authenticatable;
use Campanella\Core\Container;
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
        return 'Jelszó beállítása: <e-mail> (vagy --block / --activate)';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $args = Args::parse($args);
        $email = (string) $args->argument(0);
        $user = $email === '' ? null : $container->get(AuthService::class)->findUserByEmail($email);
        if ($user === null) {
            $output->error("Nincs ilyen felhasználó: {$email}");

            return 1;
        }
        $auth = $user->as(Authenticatable::class);
        $repository = $container->get(ObjectRepository::class);

        if ($args->flag('block') || $args->flag('activate')) {
            $args->flag('block') ? $auth->block() : $auth->activate();
            $repository->save($user);
            $output->success(sprintf('%s: %s', $user->get('email'), $auth->isActive() ? 'aktív' : 'letiltva'));

            return 0;
        }

        $password = PasswordPrompt::ask($this->input, $output);
        if ($password === null) {
            return 1;
        }
        try {
            $auth->setPassword($password);
            $repository->save($user);
        } catch (ValidationException $e) {
            foreach ($e->errors as $field => $message) {
                $output->error("{$field}: {$message}");
            }

            return 1;
        }
        $output->success('Az új jelszó beállítva: ' . $user->get('email'));

        return 0;
    }
}

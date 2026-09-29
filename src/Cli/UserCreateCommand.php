<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Auth\AuthService;
use Campanella\Capability\Authenticatable;
use Campanella\Core\Container;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;

/**
 * Új felhasználó létrehozása. A jelszót a parancs kéri be (nem jelenik meg
 * a képernyőn, és nem kerül a parancssori előzményekbe).
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
        return 'Felhasználó létrehozása: <e-mail> [--name="Név"] [--role=administrator,editor]';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $args = Args::parse($args);
        $email = (string) $args->argument(0);
        if ($email === '') {
            $output->error('Add meg az e-mail-címet: php bin/campanella user:create <e-mail> [--name="Név"] [--role=administrator]');

            return 1;
        }
        if ($container->get(AuthService::class)->findUserByEmail($email) !== null) {
            $output->error("Már van felhasználó ezzel az e-mail-címmel: {$email}");

            return 1;
        }

        $password = PasswordPrompt::ask($this->input, $output);
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
            foreach ($e->errors as $field => $message) {
                $output->error("{$field}: {$message}");
            }

            return 1;
        }
        unset($password);

        $output->success(sprintf(
            'Létrehozva: #%d %s <%s>%s',
            $user->id(),
            $name,
            $user->get('email'),
            $roles === [] ? '' : ' – szerepkörök: ' . implode(', ', $roles),
        ));

        return 0;
    }
}

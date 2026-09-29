<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Capability\Authenticatable;

/** Jelszó bekérése kétszer, egyezés- és hosszellenőrzéssel. */
final class PasswordPrompt
{
    public static function ask(Input $input, Output $output): ?string
    {
        $password = $input->secret(sprintf('Jelszó (legalább %d karakter): ', Authenticatable::MIN_PASSWORD_LENGTH));
        if ($input->isInteractive()) {
            $again = $input->secret('Jelszó még egyszer: ');
            if (!hash_equals($password, $again)) {
                $output->error('A két jelszó nem egyezik.');

                return null;
            }
        }
        if (mb_strlen($password, 'UTF-8') < Authenticatable::MIN_PASSWORD_LENGTH) {
            $output->error(sprintf('A jelszó legalább %d karakter legyen.', Authenticatable::MIN_PASSWORD_LENGTH));

            return null;
        }

        return $password;
    }
}

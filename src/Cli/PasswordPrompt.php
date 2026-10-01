<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Capability\Authenticatable;
use Campanella\I18n\Translator;

/** Asks for the password twice, checking that they match and the length. */
final class PasswordPrompt
{
    public static function ask(Input $input, Output $output, Translator $t): ?string
    {
        $min = ['min' => Authenticatable::MIN_PASSWORD_LENGTH];
        $password = $input->secret($t->translate('cli.password.prompt', $min) . ' ');
        if ($input->isInteractive()) {
            $again = $input->secret($t->translate('cli.password.again') . ' ');
            if (!hash_equals($password, $again)) {
                $output->error($t->translate('cli.password.mismatch'));

                return null;
            }
        }
        if (mb_strlen($password, 'UTF-8') < Authenticatable::MIN_PASSWORD_LENGTH) {
            $output->error($t->translate('cli.password.too_short', $min));

            return null;
        }

        return $password;
    }
}

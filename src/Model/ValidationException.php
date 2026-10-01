<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\I18n\Message;
use Campanella\I18n\Translator;

/**
 * Invalid object: per field (or relation) one message. The messages are not
 * translated here; messages() turns them into text in the current language.
 */
final class ValidationException extends \RuntimeException
{
    /** @var array<string, Message> field name => message */
    public readonly array $errors;

    /**
     * @param array<string, Message|string> $errors field name => message (a string is a message key,
     *        or a ready-made text from custom code; it is shown as it is if there is no such key)
     */
    public function __construct(array $errors)
    {
        $this->errors = array_map(
            static fn (Message|string $error): Message => $error instanceof Message ? $error : new Message($error),
            $errors,
        );
        parent::__construct('Invalid object: ' . implode('; ', array_map(
            static fn (string $field, Message $message): string => "{$field}: {$message}",
            array_keys($this->errors),
            $this->errors,
        )));
    }

    /** @return array<string, string> field name => message in the translator's language */
    public function messages(Translator $translator): array
    {
        return array_map(static fn (Message $message): string => $message->translate($translator), $this->errors);
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Http;

use Campanella\I18n\Message;

/**
 * One-time messages for the next page (e.g. "Saved." after a redirect),
 * kept in the session. Reading them removes them.
 */
final class Flash
{
    public const string SESSION_KEY = 'flash';

    public const string SUCCESS = 'success';
    public const string INFO = 'info';
    public const string WARNING = 'warning';
    public const string DANGER = 'danger';

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Adds a message. The session must be started (it is, for a logged-in user).
     *
     * @param string $type One of the constants (a Bootstrap alert variant).
     */
    public function add(string $type, Message|string $message): void
    {
        $message = $message instanceof Message ? $message : new Message($message);
        $messages = $this->stored();
        $messages[] = ['type' => $type, 'key' => $message->key, 'params' => $message->params];
        $this->session->set(self::SESSION_KEY, $messages);
    }

    /**
     * The messages, removed from the session. Does not start a session.
     *
     * @return list<array{type: string, message: Message}>
     */
    public function take(): array
    {
        $messages = $this->stored();
        if ($messages !== []) {
            $this->session->remove(self::SESSION_KEY);
        }

        return array_map(
            static fn (array $m): array => ['type' => $m['type'], 'message' => new Message($m['key'], $m['params'])],
            $messages,
        );
    }

    /** @return list<array{type: string, key: string, params: array<string, string|int|float>}> */
    private function stored(): array
    {
        $stored = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($stored)) {
            return [];
        }
        $messages = [];
        foreach ($stored as $m) {
            if (is_array($m) && is_string($m['type'] ?? null) && is_string($m['key'] ?? null) && is_array($m['params'] ?? null)) {
                /** @var array<string, string|int|float> $params */
                $params = $m['params'];
                $messages[] = ['type' => $m['type'], 'key' => $m['key'], 'params' => $params];
            }
        }

        return $messages;
    }
}

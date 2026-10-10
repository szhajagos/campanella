<?php

declare(strict_types=1);

namespace Campanella\Mail;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Closure;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends e-mails made from templates (since 0.1.3), with symfony/mailer.
 *
 * Set up in the configuration (config/local.php), never in the admin, because the
 * address of the mail server may hold a password:
 *
 *     'mail' => [
 *         'dsn' => 'smtp://user:password@smtp.example.hu:587',  // or 'native://default'
 *         'from' => 'noreply@example.hu',
 *     ],
 *
 * Without both, nothing is sent (the System page says so). A message is a template,
 * `templates/mail/<name>.txt.twig` with a `subject` and a `body` block (and, if it
 * exists, `<name>.html.twig` with a `body` block for an HTML part), so it is
 * translated with t() and a theme can override it. Every attempt is logged in the
 * `mail_log` table (kept for `mail.log_days` days). send() never throws: a failure
 * is its result, so an operation is never undone because a mail did not go out.
 * The mail server gets `mail.timeout` seconds (10); after a server failure the
 * other e-mails of the request are not tried (logged as failed), so a hanging
 * server delays a request once, not once per recipient.
 */
final class Mailer
{
    /** The longest subject (characters). */
    public const int MAX_SUBJECT = 200;

    private bool $pruned = false;

    /** The default `mail.timeout`: seconds to wait for the mail server. */
    public const int TIMEOUT = 10;

    /** Set when the mail server failed in this request: the next e-mails are not tried (a hanging server would block the request for each). */
    private ?string $failure = null;

    /**
     * @param TransportInterface|null $transport null: not configured
     * @param Closure(): Environment $twig The templates (lazy)
     * @param Closure(): string $fromName The sender's name (lazy, e.g. the site's name)
     * @param string $description The transport for people: scheme, host, port (never a password)
     */
    public function __construct(
        private readonly ?TransportInterface $transport,
        private readonly string $from,
        private readonly Closure $twig,
        private readonly Closure $fromName,
        private readonly ?Connection $db = null,
        private readonly int $logDays = 90,
        private readonly string $description = '',
        private readonly ?string $configError = null,
    ) {
    }

    /**
     * From the `mail` settings. An invalid `dsn` or `from` is not an exception: the
     * mailer is then not configured, and configError() says why.
     *
     * @param array<mixed> $config
     * @param Closure(): Environment $twig
     * @param Closure(): string $fromName
     */
    public static function fromConfig(array $config, Closure $twig, Closure $fromName, ?Connection $db = null): self
    {
        $dsn = trim((string) ($config['dsn'] ?? ''));
        $from = trim((string) ($config['from'] ?? ''));
        $name = trim((string) ($config['from_name'] ?? ''));
        $days = max(1, (int) ($config['log_days'] ?? 90));
        $timeout = max(1, (int) ($config['timeout'] ?? self::TIMEOUT));
        $fromNameFn = $name !== '' ? static fn (): string => $name : $fromName;
        if ($dsn === '' || $from === '') {
            return new self(null, $from, $twig, $fromNameFn, $db, $days, self::describeDsn($dsn));
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return new self(null, $from, $twig, $fromNameFn, $db, $days, self::describeDsn($dsn), 'mail.from is not an e-mail address');
        }
        try {
            $transport = Transport::fromDsn($dsn);
            // Not PHP's default_socket_timeout (60 s): a hanging server must not hold the request.
            if ($transport instanceof SmtpTransport && $transport->getStream() instanceof SocketStream) {
                $transport->getStream()->setTimeout($timeout);
            }
        } catch (\Throwable $e) {
            // The message may contain the DSN: only its kind is kept.
            return new self(null, $from, $twig, $fromNameFn, $db, $days, self::describeDsn($dsn), 'mail.dsn is not valid (' . (new \ReflectionClass($e))->getShortName() . ')');
        }

        return new self($transport, $from, $twig, $fromNameFn, $db, $days, self::describeDsn($dsn));
    }

    /** Whether e-mails can be sent (a valid `dsn` and `from`). */
    public function isConfigured(): bool
    {
        return $this->transport !== null;
    }

    /** Why the settings are not usable, if they are given but wrong (null otherwise). */
    public function configError(): ?string
    {
        return $this->configError;
    }

    /** The transport for people, e.g. `smtp://smtp.example.hu:587` (never the user name or password). */
    public function description(): string
    {
        return $this->description;
    }

    public function from(): string
    {
        return $this->from;
    }

    /**
     * Sends the template `mail/<template>.txt.twig` to an address.
     *
     * @param array<string, mixed> $context The template's variables (plus `to_name`)
     */
    public function send(string $to, string $template, array $context = [], string $toName = ''): MailResult
    {
        if (preg_match('/^[a-z0-9_]+\z/', $template) !== 1) {
            throw new \InvalidArgumentException("Not a mail template name: {$template}");
        }
        $subject = '';
        try {
            if ($this->transport === null) {
                return $this->logged($to, $template, '', new MailResult(MailResult::NOT_CONFIGURED));
            }
            if ($this->failure !== null) {
                return $this->logged($to, $template, '', new MailResult(MailResult::FAILED, 'not tried: the mail server failed earlier in this request (' . $this->failure . ')'));
            }
            $twig = ($this->twig)();
            $context += ['to_name' => $toName, 'to' => $to];
            $text = $twig->load("mail/{$template}.txt.twig");
            $subject = self::subjectLine($text->renderBlock('subject', $context));
            $email = (new Email())
                ->from(new Address($this->from, self::headerText(($this->fromName)())))
                ->to(new Address($to, self::headerText($toName)))
                ->subject($subject)
                ->text(trim($text->renderBlock('body', $context)) . "\n");
            if ($twig->getLoader()->exists("mail/{$template}.html.twig")) {
                $email->html($twig->load("mail/{$template}.html.twig")->renderBlock('body', $context));
            }
            // Automatic: no out-of-office replies to it.
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $this->transport->send($email);

            return $this->logged($to, $template, $subject, new MailResult(MailResult::SENT));
        } catch (TransportExceptionInterface $e) {
            $this->failure = self::errorText($e->getMessage());

            return $this->logged($to, $template, $subject, new MailResult(MailResult::FAILED, $this->failure));
        } catch (\Throwable $e) {
            // E.g. an invalid address, or a broken template.
            return $this->logged($to, $template, $subject, new MailResult(MailResult::FAILED, $e::class . ': ' . self::errorText($e->getMessage())));
        }
    }

    /**
     * The latest log entries, newest first.
     *
     * @return list<array{created_at: string, recipient: string, template: string, subject: string, status: string, error: ?string}>
     */
    public function log(int $limit = 50): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(sprintf(
            'SELECT created_at, recipient, template, subject, status, error FROM {mail_log} ORDER BY id DESC LIMIT %d',
            max(1, min(500, $limit)),
        ));

        return array_map(static fn (array $r): array => [
            'created_at' => (string) $r['created_at'],
            'recipient' => (string) $r['recipient'],
            'template' => (string) $r['template'],
            'subject' => (string) $r['subject'],
            'status' => (string) $r['status'],
            'error' => $r['error'] === null ? null : (string) $r['error'],
        ], $rows);
    }

    /** `smtp://user:pass@host:587?x=y` → `smtp://host:587`; '' for an empty DSN. */
    public static function describeDsn(string $dsn): string
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return '';
        }
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'])) {
            return '?';
        }

        return $parts['scheme'] . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function logged(string $to, string $template, string $subject, MailResult $result): MailResult
    {
        if ($result->status === MailResult::FAILED) {
            error_log("Campanella: the e-mail '{$template}' to {$to} was not sent: " . $result->error);
        }
        if ($this->db === null) {
            return $result;
        }
        try {
            $now = gmdate('Y-m-d H:i:s');
            $this->db->insert(CoreSchema::MAIL_LOG, [
                'created_at' => $now,
                'recipient' => mb_substr($to, 0, 255),
                'template' => $template,
                'subject' => mb_substr($subject, 0, 255),
                'status' => $result->status,
                'error' => $result->error,
            ]);
            if (!$this->pruned) {
                $this->pruned = true;
                $this->db->execute('DELETE FROM {mail_log} WHERE created_at < :before', ['before' => gmdate('Y-m-d H:i:s', time() - $this->logDays * 86400)]);
            }
        } catch (\PDOException $e) {
            error_log('Campanella: the mail log could not be written: ' . $e->getMessage());
        }

        return $result;
    }

    /** One line, no control characters, at most MAX_SUBJECT characters. */
    private static function subjectLine(string $subject): string
    {
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($subject, 0, self::MAX_SUBJECT);
    }

    /** A name for a header: one line, without control characters. */
    private static function headerText(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)), 0, 100);
    }

    private static function errorText(string $message): string
    {
        // A transport's message may echo the server's address with credentials.
        $message = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^@\s/]*@#i', '$1***@', $message);

        return mb_substr(trim($message), 0, 500);
    }
}

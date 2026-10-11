<?php

declare(strict_types=1);

namespace Campanella\Webform;

use Campanella\Auth\LoginGuard;
use Campanella\Capability\Submitted;
use Campanella\Http\Request;
use Campanella\Http\Session;
use Campanella\I18n\Message;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;
use Campanella\Service\SubmissionService;

/**
 * The contact form (since 0.1.5): checks a sending and saves it as a submission.
 *
 * Protections, in this order:
 *  - a CSRF token (the session's), so another site cannot send it in a visitor's name;
 *  - the guards (`contact.guards`, by default the honeypot): a bot that fills in the
 *    hidden field is told "sent", and nothing is saved;
 *  - the time the form was shown, in a hidden field signed with a secret kept only in
 *    the session (never sent to the browser), so it cannot be forged, nor carried to
 *    another session: older than a day, it has expired;
 *  - the fields (shown again with their errors);
 *  - sent sooner than `contact.min_seconds` (3) after it was shown: taken for a bot's,
 *    and shown again;
 *  - 5 messages per IP address (IPv6: per /48 network) and 3 per sender's e-mail
 *    address (`+tags` ignored) in an hour: only messages that would be saved count,
 *    not the corrections of a typo. (The notification e-mails have a limit on the
 *    whole site: MailSubmission; a limit on the messages themselves would let a few
 *    addresses close the form for everyone.)
 *  - lengths: name 100, e-mail address 254, subject 200, message 5,000 characters
 *    (a line break counts as one, as in the browser).
 */
final class ContactForm
{
    public const string FORM = 'contact';
    public const string TIME_FIELD = '_ts';

    public const int MIN_SECONDS = 3;
    /** A form shown longer ago than this (seconds) has expired. */
    public const int MAX_AGE = 86400;
    public const int MAX_PER_IP = 5;
    public const int MAX_PER_ADDRESS = 3;
    public const int DECAY_SECONDS = 3600;

    /** The session key of the secret that signs the time the form was shown. */
    public const string SESSION_KEY = 'contact_form_key';

    /** The fields, with their longest values. */
    public const array FIELDS = [
        'name' => SubmissionService::MAX_NAME,
        'email' => Submitted::MAX_EMAIL,
        'subject' => Submitted::MAX_SUBJECT,
        'message' => Submitted::MAX_MESSAGE,
    ];

    /** @param list<LoginGuard> $guards */
    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly Csrf $csrf,
        private readonly Throttle $throttle,
        private readonly Session $session,
        private readonly array $guards = [],
        private readonly bool $enabled = true,
        private readonly int $minSeconds = self::MIN_SECONDS,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * What the form's template needs besides the values: the signed time it was shown,
     * the guards' fields, and how long messages are kept. Starts the session (for the
     * CSRF token).
     *
     * @return array{ts: string, guard_fields: string, keep_days: int}
     */
    public function fields(Request $request): array
    {
        $time = (string) time();

        return [
            'ts' => $time . '.' . $this->sign($request, $time),
            'guard_fields' => implode('', array_map(static fn (LoginGuard $guard): string => $guard->fields(), $this->guards)),
            // For the privacy note under the form.
            'keep_days' => $this->submissions->keepDays(),
        ];
    }

    /** Checks a sending and, if all is well, saves it. */
    public function submit(Request $request): ContactResult
    {
        if (!$this->csrf->isValid($request)) {
            // Not shown again: another site must not put its text on this site's page.
            return new ContactResult(ContactResult::EXPIRED, message: new Message('auth.form_expired'));
        }
        $values = [];
        $shown = [];
        foreach (self::FIELDS as $field => $max) {
            // As the browser counts: a line break is one character.
            $values[$field] = str_replace(["\r\n", "\r"], "\n", $request->postString($field));
            // Shown again at most this long (a much longer value is an error anyway).
            $shown[$field] = mb_substr($values[$field], 0, $max + 100, 'UTF-8');
        }
        foreach ($this->guards as $guard) {
            if ($guard->check($request) !== null) {
                return new ContactResult(ContactResult::IGNORED);
            }
        }
        $age = $this->age($request);
        if ($age === null || $age > self::MAX_AGE) {
            return new ContactResult(ContactResult::EXPIRED, $shown, message: new Message('auth.form_expired'));
        }
        // The fields first, so a quick human sees what to fix.
        $errors = self::formErrors($this->submissions->check($values['name'], $values['email'], $values['subject'], $values['message']));
        if ($errors !== []) {
            return new ContactResult(ContactResult::INVALID, $shown, $errors, new Message('contact.invalid'));
        }
        if ($age < max(0, $this->minSeconds)) {
            return new ContactResult(ContactResult::TOO_FAST, $shown, message: new Message('contact.too_fast'));
        }
        // Only messages that would be saved count (not a correction of a typo).
        foreach ([
            ['contact-ip|' . self::networkOf($request->ip), self::MAX_PER_IP],
            ['contact-address|' . self::addressKey($values['email']), self::MAX_PER_ADDRESS],
        ] as [$key, $max]) {
            if ($this->throttle->tooManyAttempts($key, $max) || $this->throttle->hit($key, self::DECAY_SECONDS) > $max) {
                return $this->tooMany($shown, $key);
            }
        }
        try {
            $submission = $this->submissions->submit($values['name'], $values['email'], $values['subject'], $values['message'], self::FORM);
        } catch (ValidationException $e) {
            return new ContactResult(ContactResult::INVALID, $shown, self::formErrors($e->errors), new Message('contact.invalid'));
        }

        return new ContactResult(ContactResult::SENT, submission: $submission);
    }

    /**
     * The address a limit counts by: an IPv4 address, or an IPv6 address's /48 network
     * (one connection often has a whole /48, so a /64 would let it send 65,536 times more).
     */
    public static function networkOf(string $ip): string
    {
        $ip = Request::normalizeIp($ip);
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        return (string) inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** An e-mail address as a limit counts it: lowercase, without a `+tag` (anna+1@ is anna@). */
    public static function addressKey(string $email): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $plus = strpos($local, '+');

        return ($plus === false ? $local : substr($local, 0, $plus)) . substr($email, $at);
    }

    /**
     * The form's field names: the name is the object's title, the address `sender_email`.
     *
     * @param array<string, Message|string> $errors
     * @return array<string, Message|string>
     */
    private static function formErrors(array $errors): array
    {
        $result = [];
        foreach ($errors as $field => $message) {
            $result[match ($field) { 'title' => 'name', 'sender_email' => 'email', default => $field }] = $message;
        }

        return $result;
    }

    /** @param array<string, string> $values */
    private function tooMany(array $values, string $key): ContactResult
    {
        return new ContactResult(ContactResult::TOO_MANY, $values, message: new Message('contact.too_many', [
            'minutes' => max(1, (int) ceil($this->throttle->availableIn($key) / 60)),
        ]));
    }

    /** Seconds since the form was shown, or null if its signed time is missing or forged. */
    private function age(Request $request): ?int
    {
        $parts = explode('.', $request->postString(self::TIME_FIELD), 2);
        if (count($parts) !== 2 || preg_match('/^\d{1,12}$/', $parts[0]) !== 1
            || !hash_equals($this->sign($request, $parts[0]), $parts[1])) {
            return null;
        }
        $age = time() - (int) $parts[0];

        return $age < 0 ? null : $age;
    }

    private function sign(Request $request, string $time): string
    {
        return hash_hmac('sha256', self::FORM . '|' . $time, $this->secret($request));
    }

    /** The session's secret for signing the time: never sent to the browser (unlike the CSRF token). */
    private function secret(Request $request): string
    {
        $this->session->start($request);
        $secret = $this->session->get(self::SESSION_KEY);
        if (!is_string($secret) || strlen($secret) !== 64) {
            $secret = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $secret);
        }

        return $secret;
    }
}

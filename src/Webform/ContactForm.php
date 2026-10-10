<?php

declare(strict_types=1);

namespace Campanella\Webform;

use Campanella\Auth\AuthService;
use Campanella\Auth\LoginGuard;
use Campanella\Capability\Submitted;
use Campanella\Http\Request;
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
 *  - the time the form was shown, in a hidden field signed with the session's CSRF
 *    token (so it cannot be forged or reused in another session): older than a day,
 *    it has expired;
 *  - the fields (shown again with their errors);
 *  - sent sooner than `contact.min_seconds` (3) after it was shown: taken for a bot's,
 *    and shown again;
 *  - 5 messages per IP address (IPv6: /64) and 3 per sender's e-mail address in an
 *    hour (only messages that would be saved count, not the corrections of a typo);
 *  - lengths: name 100, e-mail address 254, subject 200, message 5,000 characters.
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
     * and the guards' fields. Starts the session (for the CSRF token).
     *
     * @return array{ts: string, guard_fields: string}
     */
    public function fields(Request $request): array
    {
        $time = (string) time();

        return [
            'ts' => $time . '.' . $this->sign($request, $time),
            'guard_fields' => implode('', array_map(static fn (LoginGuard $guard): string => $guard->fields(), $this->guards)),
        ];
    }

    /** Checks a sending and, if all is well, saves it. */
    public function submit(Request $request): ContactResult
    {
        $values = [];
        foreach (self::FIELDS as $field => $max) {
            // Kept for showing the form again; a much longer value is cut there.
            $values[$field] = mb_substr($request->postString($field), 0, $max + 100, 'UTF-8');
        }
        if (!$this->csrf->isValid($request)) {
            return new ContactResult(ContactResult::EXPIRED, $values, message: new Message('auth.form_expired'));
        }
        foreach ($this->guards as $guard) {
            if ($guard->check($request) !== null) {
                return new ContactResult(ContactResult::IGNORED);
            }
        }
        $age = $this->age($request);
        if ($age === null || $age > self::MAX_AGE) {
            return new ContactResult(ContactResult::EXPIRED, $values, message: new Message('auth.form_expired'));
        }
        // The fields first, so a quick human sees what to fix.
        $errors = self::formErrors($this->submissions->check($values['name'], $values['email'], $values['subject'], $values['message']));
        if ($errors !== []) {
            return new ContactResult(ContactResult::INVALID, $values, $errors, new Message('contact.invalid'));
        }
        if ($age < max(0, $this->minSeconds)) {
            return new ContactResult(ContactResult::TOO_FAST, $values, message: new Message('contact.too_fast'));
        }
        // Only messages that would be saved count (not a correction of a typo).
        $ipKey = 'contact-ip|' . AuthService::clientKey($request->ip);
        if ($this->throttle->tooManyAttempts($ipKey, self::MAX_PER_IP) || $this->throttle->hit($ipKey, self::DECAY_SECONDS) > self::MAX_PER_IP) {
            return $this->tooMany($values, $ipKey);
        }
        $addressKey = 'contact-address|' . mb_strtolower(trim($values['email']), 'UTF-8');
        if ($this->throttle->tooManyAttempts($addressKey, self::MAX_PER_ADDRESS) || $this->throttle->hit($addressKey, self::DECAY_SECONDS) > self::MAX_PER_ADDRESS) {
            return $this->tooMany($values, $addressKey);
        }
        try {
            $submission = $this->submissions->submit($values['name'], $values['email'], $values['subject'], $values['message'], self::FORM);
        } catch (ValidationException $e) {
            return new ContactResult(ContactResult::INVALID, $values, self::formErrors($e->errors), new Message('contact.invalid'));
        }

        return new ContactResult(ContactResult::SENT, submission: $submission);
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
        return hash_hmac('sha256', self::FORM . '|' . $time, $this->csrf->token($request));
    }
}

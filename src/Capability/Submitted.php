<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The object is a message sent through a form of the site (since 0.1.5): the
 * contact form's submissions (the `submission` Blueprint; the sender's name is its
 * title).
 *
 * Personal data: only administrators may see or handle such objects (DefaultPolicy),
 * and the fields are hidden from templates. The sender's IP address is not stored.
 */
#[AsCapability('submitted', requires: [Titled::class], label: 'capability.submitted')]
final class Submitted extends Capability
{
    /** The longest message, in characters. */
    public const int MAX_MESSAGE = 5000;
    public const int MAX_SUBJECT = 200;
    public const int MAX_EMAIL = 254;

    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('sender_email', FieldType::String, required: true, length: self::MAX_EMAIL, label: 'field.email', hidden: true),
            new Field('subject', FieldType::String, length: self::MAX_SUBJECT, label: 'field.subject', hidden: true),
            new Field('message', FieldType::Text, required: true, label: 'field.message', hidden: true),
            // Which form it came from (the contact form: `contact`).
            new Field('form', FieldType::String, required: true, indexed: true, length: 64, default: 'contact', label: 'field.form'),
            // When an administrator first opened it; null: unread.
            new Field('read_at', FieldType::DateTime, indexed: true, label: 'field.read_at', hidden: true),
        ];
    }

    public function email(): string
    {
        return (string) $this->object->get('sender_email');
    }

    public function subject(): string
    {
        return (string) $this->object->get('subject');
    }

    public function message(): string
    {
        return (string) $this->object->get('message');
    }

    public function form(): string
    {
        return (string) $this->object->get('form');
    }

    public function readAt(): ?\DateTimeImmutable
    {
        $value = $this->object->get('read_at');

        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    public function isRead(): bool
    {
        return $this->readAt() !== null;
    }

    public function markRead(?\DateTimeImmutable $at = null): void
    {
        $this->object->set('read_at', $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public function markUnread(): void
    {
        $this->object->set('read_at', null);
    }

    #[\Override]
    public function prepareForSave(): void
    {
        $email = mb_strtolower(trim($this->email()), 'UTF-8');
        $this->object->set('sender_email', $email);
        $this->object->set('subject', trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $this->subject())));
        // Line breaks stay; other control characters go.
        $this->object->set('message', trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace("\r\n", "\n", $this->message()))));
    }

    #[\Override]
    public function validate(): array
    {
        $errors = [];
        $email = $this->email();
        if ($email !== '' && (strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['sender_email'] = new Message('validation.invalid_email');
        }
        if (mb_strlen($this->subject(), 'UTF-8') > self::MAX_SUBJECT) {
            $errors['subject'] = new Message('validation.value_too_long', ['max' => self::MAX_SUBJECT]);
        }
        if (mb_strlen($this->message(), 'UTF-8') > self::MAX_MESSAGE) {
            $errors['message'] = new Message('validation.value_too_long', ['max' => self::MAX_MESSAGE]);
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $this->form()) !== 1) {
            $errors['form'] = new Message('validation.machine_name');
        }

        return $errors;
    }
}

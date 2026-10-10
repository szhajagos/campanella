<?php

declare(strict_types=1);

namespace Campanella\Service;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Submitted;
use Campanella\Event\EventDispatcher;
use Campanella\Event\FormSubmitted;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Query\ResultSet;

/**
 * The messages sent through the site's forms (since 0.1.5): saving a new one, and
 * reading, marking and deleting them in the admin. Only administrators may see them
 * (the access policy); saving a new one needs no actor (the visitor sends it).
 */
final class SubmissionService
{
    public const string BLUEPRINT = 'submission';

    /** The longest sender's name, in characters. */
    public const int MAX_NAME = 100;

    /** Submissions per page in the admin. */
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly QueryEngine $queries,
        private readonly AccessPolicy $policy,
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    /**
     * The problems of a message from a form, without saving it: field => message key
     * or Message (`title` is the name). Empty: it can be saved.
     *
     * @return array<string, Message|string>
     */
    public function check(string $name, string $email, string $subject, string $message): array
    {
        $name = self::cleanName($name);
        $email = trim($email);
        $errors = [];
        if ($name === '') {
            $errors['title'] = 'validation.required';
        } elseif (mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            $errors['title'] = new Message('validation.value_too_long', ['max' => self::MAX_NAME]);
        }
        if ($email === '') {
            $errors['sender_email'] = 'validation.required';
        } elseif (strlen($email) > Submitted::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['sender_email'] = 'validation.invalid_email';
        }
        if (mb_strlen(trim($subject), 'UTF-8') > Submitted::MAX_SUBJECT) {
            $errors['subject'] = new Message('validation.value_too_long', ['max' => Submitted::MAX_SUBJECT]);
        }
        if (trim($message) === '') {
            $errors['message'] = 'validation.required';
        } elseif (mb_strlen(trim($message), 'UTF-8') > Submitted::MAX_MESSAGE) {
            $errors['message'] = new Message('validation.value_too_long', ['max' => Submitted::MAX_MESSAGE]);
        }

        return $errors;
    }

    /**
     * Saves a message from a form (the visitor's input, checked here: name, e-mail
     * address, message required; lengths limited), then dispatches FormSubmitted.
     *
     * @throws ValidationException on `title` (the name), `sender_email`, `subject`, `message`
     */
    public function submit(string $name, string $email, string $subject, string $message, string $form = 'contact'): CampanellaObject
    {
        $errors = $this->check($name, $email, $subject, $message);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $submission = $this->repository->create(self::BLUEPRINT, [
            'title' => self::cleanName($name),
            'sender_email' => $email,
            'subject' => $subject,
            'message' => $message,
            'form' => $form,
        ]);
        $this->repository->save($submission);
        $this->events?->dispatch(new FormSubmitted($submission, $form));

        return $submission;
    }

    private static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
    }

    /** Whether the actor may see the submissions (by default: administrators). */
    public function canView(Actor $actor): bool
    {
        return $this->policy->allows($actor, Operation::View, $this->repository->create(self::BLUEPRINT, []));
    }

    /** A page of the submissions, newest first; `$unreadOnly`: only the unread ones. */
    public function page(Actor $actor, int $page = 1, bool $unreadOnly = false): ResultSet
    {
        $query = Query::objects()->blueprint(self::BLUEPRINT)->orderBy('created', 'DESC')->page(max(1, $page), self::PER_PAGE);

        return $this->queries->execute($unreadOnly ? $query->where('read_at', 'IS NULL') : $query, $actor, withTotal: true);
    }

    public function unreadCount(Actor $actor): int
    {
        return $this->queries->count(Query::objects()->blueprint(self::BLUEPRINT)->where('read_at', 'IS NULL'), $actor);
    }

    /** A submission the actor may see, or null. */
    public function find(Actor $actor, int $id): ?CampanellaObject
    {
        return $this->queries->first(Query::objects()->blueprint(self::BLUEPRINT)->where('id', '=', $id), $actor);
    }

    /** Marks it read (first opened) or unread. */
    public function mark(Actor $actor, CampanellaObject $submission, bool $read): void
    {
        $this->authorize($actor, Operation::Update, $submission);
        $lens = $submission->as(Submitted::class);
        if ($read === $lens->isRead()) {
            return;
        }
        $read ? $lens->markRead() : $lens->markUnread();
        $this->repository->save($submission);
    }

    public function delete(Actor $actor, CampanellaObject $submission): void
    {
        $this->authorize($actor, Operation::Delete, $submission);
        $this->repository->delete($submission);
    }

    private function authorize(Actor $actor, Operation $operation, CampanellaObject $submission): void
    {
        if (!$submission->has(Submitted::class) || !$this->policy->allows($actor, $operation, $submission)) {
            throw AccessDeniedException::for($actor, $operation, "submission #{$submission->id()}");
        }
    }
}

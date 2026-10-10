<?php

declare(strict_types=1);

namespace Campanella\Service;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Submitted;
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

    /** Submissions per page in the admin. */
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly QueryEngine $queries,
        private readonly AccessPolicy $policy,
    ) {
    }

    /**
     * Saves a message from a form (the visitor's input, checked here: name, e-mail
     * address, message required; lengths limited).
     *
     * @throws ValidationException on `title` (the name), `sender_email`, `subject`, `message`
     */
    public function submit(string $name, string $email, string $subject, string $message, string $form = 'contact'): CampanellaObject
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $errors = [];
        if ($name === '') {
            $errors['title'] = 'validation.required';
        } elseif (mb_strlen($name, 'UTF-8') > 100) {
            $errors['title'] = new \Campanella\I18n\Message('validation.value_too_long', ['max' => 100]);
        }
        if (trim($email) === '') {
            $errors['sender_email'] = 'validation.required';
        }
        if (trim($message) === '') {
            $errors['message'] = 'validation.required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $submission = $this->repository->create(self::BLUEPRINT, [
            'title' => $name,
            'sender_email' => $email,
            'subject' => $subject,
            'message' => $message,
            'form' => $form,
        ]);
        $this->repository->save($submission);

        return $submission;
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

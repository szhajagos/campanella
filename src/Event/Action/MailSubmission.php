<?php

declare(strict_types=1);

namespace Campanella\Event\Action;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Capability\AccountStatus;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\Submitted;
use Campanella\Core\Config;
use Campanella\Core\Container;
use Campanella\Core\Deferred;
use Campanella\Event\Action;
use Campanella\Event\Event;
use Campanella\Event\FormSubmitted;
use Campanella\Mail\Mailer;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\SubmissionService;
use Campanella\Service\UserService;
use Campanella\Site\SiteSettings;

/**
 * E-mails a form's new message (FormSubmitted) to the given addresses, or to the
 * active administrators (since 0.1.5). The Kernel binds it to the contact form by
 * default (`contact.notify`, `contact.notify_to`).
 *
 * The e-mail (the `submission` template) holds the sender's name and address, the
 * subject and the message as plain text, and a link to it in the admin; replying to
 * it goes to the sender (`Reply-To`). It is sent after the response (Deferred), so the
 * visitor does not wait for the mail server. Its subject holds nothing the sender
 * wrote: the mail log keeps the subject.
 */
final class MailSubmission implements Action
{
    /** The most recipients of one message. */
    public const int MAX_RECIPIENTS = 20;

    /** @param list<string> $recipients Addresses; empty: the active administrators */
    public function __construct(
        private readonly Mailer $mailer,
        private readonly QueryEngine $queries,
        private readonly array $recipients = [],
        private readonly ?SiteSettings $site = null,
        private readonly ?AdminAccess $admin = null,
        private readonly ?Deferred $deferred = null,
    ) {
    }

    #[\Override]
    public static function create(Container $container): self
    {
        return new self(
            $container->get(Mailer::class),
            $container->get(QueryEngine::class),
            self::recipientsOf($container->get(Config::class)->get('contact.notify_to', [])),
            $container->get(SiteSettings::class),
            $container->get(AdminAccess::class),
            $container->get(Deferred::class),
        );
    }

    /**
     * The valid addresses of a `notify_to` setting (a list, or one address).
     *
     * @return list<string>
     */
    public static function recipientsOf(mixed $setting): array
    {
        $addresses = [];
        foreach (is_array($setting) ? $setting : [$setting] as $address) {
            $address = is_string($address) ? mb_strtolower(trim($address), 'UTF-8') : '';
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[$address] = true;
            }
        }

        return array_slice(array_keys($addresses), 0, self::MAX_RECIPIENTS);
    }

    #[\Override]
    public function handle(Event $event): void
    {
        if (!$event instanceof FormSubmitted || !$this->mailer->isConfigured()) {
            return;
        }
        $submission = $event->submission;
        if (!$submission->has(Submitted::class)) {
            return;
        }
        $lens = $submission->as(Submitted::class);
        $context = [
            'form' => $event->form,
            'sender_name' => (string) $submission->get('title'),
            'sender_email' => $lens->email(),
            'subject' => $lens->subject(),
            'message' => $lens->message(),
            'received_at' => $submission->created()->format('Y-m-d H:i') . ' UTC',
            'link' => $this->site !== null && $this->admin !== null
                ? $this->site->absolute($this->admin->path(SubmissionService::BLUEPRINT . '/' . $submission->id()))
                : null,
        ];
        $send = function () use ($context): void {
            foreach ($this->recipients() as $address => $name) {
                $this->mailer->send($address, 'submission', $context, $name, $context['sender_email'], $context['sender_name']);
            }
        };
        $this->deferred !== null ? $this->deferred->add($send) : $send();
    }

    /** @return array<string, string> address => name */
    private function recipients(): array
    {
        if ($this->recipients !== []) {
            return array_fill_keys($this->recipients, '');
        }
        $result = [];
        $administrators = $this->queries->execute(
            Query::objects()
                ->having(Authenticatable::class)
                ->where('roles', '=', Actor::ADMINISTRATOR)
                ->where('account_status', '=', AccountStatus::Active->value)
                ->orderBy('id')
                ->limit(self::MAX_RECIPIENTS),
            Actor::system(),
        );
        foreach ($administrators as $administrator) {
            $result[$administrator->as(Identifiable::class)->email()] = UserService::nameOf($administrator);
        }

        return $result;
    }
}

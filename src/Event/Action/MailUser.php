<?php

declare(strict_types=1);

namespace Campanella\Event\Action;

use Campanella\Auth\PasswordReset;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Identifiable;
use Campanella\Core\Container;
use Campanella\Event\Action;
use Campanella\Event\Event;
use Campanella\Event\PasswordChanged;
use Campanella\Event\UserCreated;
use Campanella\Event\UserEvent;
use Campanella\Http\SitePaths;
use Campanella\Mail\Mailer;
use Campanella\Service\UserService;
use Campanella\Site\SiteSettings;

/**
 * E-mails the user an event is about (a UserEvent; since 0.1.4): their account was
 * created, their password was changed. Not bound to anything by default, e.g. an
 * administrator setting someone's password to lock them out may not want them told:
 *
 *     'events' => [
 *         PasswordChanged::class => [MailUser::class],
 *         UserCreated::class => [MailUser::class],
 *     ],
 *
 * The template is `user_created` or `user_password_changed`, `user_event` for any
 * other UserEvent; a theme overrides them with its own mail/<name>.txt.twig. A blocked
 * account gets nothing; other events are ignored. The e-mail never holds a password.
 */
final class MailUser implements Action
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly ?SiteSettings $site = null,
        private readonly ?SitePaths $paths = null,
        private readonly ?PasswordReset $reset = null,
    ) {
    }

    #[\Override]
    public static function create(Container $container): self
    {
        return new self(
            $container->get(Mailer::class),
            $container->get(SiteSettings::class),
            $container->get(SitePaths::class),
            $container->get(PasswordReset::class),
        );
    }

    /** The template for an event. */
    public static function templateFor(Event $event): string
    {
        return match (true) {
            $event instanceof UserCreated => 'user_created',
            $event instanceof PasswordChanged => 'user_password_changed',
            default => 'user_event',
        };
    }

    #[\Override]
    public function handle(Event $event): void
    {
        if (!$event instanceof UserEvent || !$this->mailer->isConfigured()) {
            return;
        }
        $user = $event->user();
        if (!$user->has(Identifiable::class) || ($user->has(Authenticatable::class) && !$user->as(Authenticatable::class)->isActive())) {
            return;
        }
        $this->mailer->send($user->as(Identifiable::class)->email(), self::templateFor($event), [
            'event' => $event->name(),
            'summary' => $event->summary(),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i') . ' UTC',
            'by_administrator' => $event instanceof PasswordChanged && $event->byAdministrator,
            'by_reset' => $event instanceof PasswordChanged && $event->byReset,
            'login_link' => $this->link('login'),
            'reset_link' => $this->reset?->isAvailable() === true ? $this->link('password_reset') : null,
        ], UserService::nameOf($user));
    }

    /** A page of the site as an absolute URL (null without the site's address). */
    private function link(string $page): ?string
    {
        return $this->site === null || $this->paths === null ? null : $this->site->absolute($this->paths->get($page));
    }
}

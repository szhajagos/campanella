<?php

declare(strict_types=1);

namespace Campanella\Event\Action;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Capability\AccountStatus;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Identifiable;
use Campanella\Core\Container;
use Campanella\Event\Action;
use Campanella\Event\Event;
use Campanella\Event\FormSubmitted;
use Campanella\Event\ObjectDeleted;
use Campanella\Event\ObjectEvent;
use Campanella\Event\PasswordChanged;
use Campanella\Event\UserCreated;
use Campanella\Mail\Mailer;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\UserService;
use Campanella\Site\SiteSettings;

/**
 * Tells the active administrators about an event by e-mail (the `event` mail
 * template), with a link to the object in the admin if the site's address is set.
 * The one who did it gets no mail about it. Since 0.1.3; not bound to anything by
 * default:
 *
 *     'events' => [ObjectPublished::class => [MailAdministrators::class]],
 */
final class MailAdministrators implements Action
{
    /** The most administrators mailed about one event. */
    public const int MAX_RECIPIENTS = 50;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly QueryEngine $queries,
        private readonly ?SiteSettings $site = null,
        private readonly ?AdminAccess $admin = null,
    ) {
    }

    #[\Override]
    public static function create(Container $container): self
    {
        return new self(
            $container->get(Mailer::class),
            $container->get(QueryEngine::class),
            $container->get(SiteSettings::class),
            $container->get(AdminAccess::class),
        );
    }

    #[\Override]
    public function handle(Event $event): void
    {
        if (!$this->mailer->isConfigured()) {
            return;
        }
        $actor = $event->actor;
        $context = [
            'event' => $event->name(),
            'summary' => $event->summary(),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i') . ' UTC',
            'actor_name' => $actor?->id !== null ? $this->nameOf($actor) : '',
            'link' => $this->link($event),
        ];
        foreach ($this->administrators() as $administrator) {
            if ($actor !== null && $actor->id !== null && $actor->id === $administrator->id()) {
                continue;
            }
            $this->mailer->send($administrator->as(Identifiable::class)->email(), 'event', $context, UserService::nameOf($administrator));
        }
    }

    /** @return list<CampanellaObject> */
    private function administrators(): array
    {
        return $this->queries->execute(
            Query::objects()
                ->having(Authenticatable::class)
                ->where('roles', '=', Actor::ADMINISTRATOR)
                ->where('account_status', '=', AccountStatus::Active->value)
                ->orderBy('id')
                ->limit(self::MAX_RECIPIENTS),
            Actor::system(),
        )->items;
    }

    private function nameOf(Actor $actor): string
    {
        $user = $this->queries->first(Query::objects()->having(Authenticatable::class)->where('id', '=', (int) $actor->id), Actor::system());

        return $user === null ? '#' . $actor->id : UserService::nameOf($user);
    }

    /** The object's page in the admin, as an absolute URL (null without the site's address). */
    private function link(Event $event): ?string
    {
        if ($this->site === null || $this->admin === null || $event instanceof ObjectDeleted) {
            return null;
        }
        $path = match (true) {
            $event instanceof ObjectEvent => $event->object->blueprint() . '/' . $event->object->id(),
            $event instanceof UserCreated => UserService::BLUEPRINT . '/' . $event->user->id(),
            $event instanceof PasswordChanged => UserService::BLUEPRINT . '/' . $event->user->id(),
            $event instanceof FormSubmitted => \Campanella\Service\SubmissionService::BLUEPRINT . '/' . $event->submission->id(),
            default => null,
        };

        return $path === null ? null : $this->site->absolute($this->admin->path($path));
    }
}

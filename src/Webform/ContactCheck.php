<?php

declare(strict_types=1);

namespace Campanella\Webform;

use Campanella\Access\Actor;
use Campanella\Event\Action\MailSubmission;
use Campanella\Http\Request;
use Campanella\Http\SitePaths;
use Campanella\I18n\Message;
use Campanella\Mail\Mailer;
use Campanella\Service\SubmissionService;
use Campanella\Site\SiteSettings;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/**
 * The system check's *Contact form* lines (since 0.1.5): whether it is on, whether its
 * messages are e-mailed (and to whom), how long they are kept, the privacy notice,
 * and the messages waiting.
 */
final class ContactCheck
{
    /**
     * @param array<string, mixed> $config The `contact` settings
     * @return Closure(?Request): list<CheckResult> For SystemCheck::add()
     */
    public static function checks(array $config, ContactForm $form, SubmissionService $submissions, Mailer $mailer, SiteSettings $site, SitePaths $paths): Closure
    {
        return static function (?Request $request) use ($config, $form, $submissions, $mailer, $site, $paths): array {
            $g = 'admin.system.group.contact';
            if (!$form->isEnabled()) {
                return [new CheckResult($g, 'admin.system.contact_form', CheckStatus::Info, 'admin.system.contact_off')];
            }
            $results = [new CheckResult($g, 'admin.system.contact_form', CheckStatus::Ok, $paths->get('contact'))];

            $recipients = MailSubmission::recipientsOf($config['notify_to'] ?? []);
            $results[] = match (true) {
                !($config['notify'] ?? true) => new CheckResult($g, 'admin.system.contact_notify', CheckStatus::Info, 'admin.system.contact_notify_off'),
                !$mailer->isConfigured() => new CheckResult($g, 'admin.system.contact_notify', CheckStatus::Warning, 'admin.system.contact_notify_no_mail', new Message('admin.system.contact_notify_no_mail_hint')),
                default => new CheckResult($g, 'admin.system.contact_notify', CheckStatus::Ok, $recipients !== [] ? implode(', ', $recipients) : 'admin.system.contact_notify_admins'),
            };

            $days = $submissions->keepDays();
            $results[] = $days > 0
                ? new CheckResult($g, 'admin.system.contact_keep', CheckStatus::Ok, (string) $days, new Message('admin.system.contact_keep_days', ['days' => $days]))
                : new CheckResult($g, 'admin.system.contact_keep', CheckStatus::Warning, 'admin.system.contact_keep_forever', new Message('admin.system.contact_keep_forever_hint'));

            $results[] = $site->privacyUrl() !== ''
                ? new CheckResult($g, 'admin.system.contact_privacy', CheckStatus::Ok, $site->privacyUrl())
                : new CheckResult($g, 'admin.system.contact_privacy', CheckStatus::Info, 'admin.system.contact_privacy_none', new Message('admin.system.contact_privacy_hint'));

            try {
                $unread = $submissions->unreadCount(Actor::system());
                $results[] = new CheckResult($g, 'admin.system.contact_unread', $unread > 0 ? CheckStatus::Info : CheckStatus::Ok, (string) $unread);
            } catch (\PDOException) {
                // Before the upgrade that creates the messages' table.
            }

            return $results;
        };
    }
}

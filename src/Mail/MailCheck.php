<?php

declare(strict_types=1);

namespace Campanella\Mail;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/**
 * The system check's *E-mail* lines (since 0.1.3): whether sending is set up (with a
 * button for a test e-mail to oneself), and how the latest attempt went.
 */
final class MailCheck
{
    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(Mailer $mailer): Closure
    {
        return static function (?Request $request) use ($mailer): array {
            $g = 'admin.system.group.mail';
            $results = [];
            if ($mailer->configError() !== null) {
                $results[] = new CheckResult($g, 'admin.system.mail_transport', CheckStatus::Error, $mailer->description() ?: '–', new Message('admin.system.mail_invalid', ['error' => $mailer->configError()]));
            } elseif (!$mailer->isConfigured()) {
                $results[] = new CheckResult($g, 'admin.system.mail_transport', CheckStatus::Warning, 'admin.system.mail_off', new Message('admin.system.mail_off_hint'));
            } else {
                $results[] = new CheckResult($g, 'admin.system.mail_transport', CheckStatus::Ok, $mailer->description(), null, 'system/mail-test', 'admin.system.mail_test');
                $results[] = new CheckResult($g, 'admin.system.mail_from', CheckStatus::Ok, $mailer->from());
            }
            try {
                $last = $mailer->log(1)[0] ?? null;
            } catch (\PDOException) {
                $last = null; // before the upgrade that creates the log
            }
            if ($last !== null) {
                $value = $last['created_at'] . ' UTC · ' . $last['recipient'];
                $results[] = $last['status'] === MailResult::SENT
                    ? new CheckResult($g, 'admin.system.mail_last', CheckStatus::Ok, $value)
                    : new CheckResult($g, 'admin.system.mail_last', $last['status'] === MailResult::FAILED ? CheckStatus::Warning : CheckStatus::Info, $value,
                        new Message('admin.system.mail_last_' . $last['status'], ['error' => (string) $last['error']]));
            }

            return $results;
        };
    }
}

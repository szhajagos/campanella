<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Capability\Identifiable;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\Mail\Mailer;
use Campanella\Model\ObjectRepository;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;
use Campanella\Service\UserService;
use Closure;

/**
 * The admin's e-mail pages, for the System page's roles (since 0.1.3):
 *
 *   /admin/system/mail             the latest e-mails sent (or tried)
 *   POST /admin/system/mail-test   a test e-mail to one's own address
 *
 * The test goes only to the logged-in user's address, so the page cannot be used to
 * send mail to anyone else; at most MAX_TESTS in TEST_DECAY_SECONDS.
 */
final class MailPages
{
    public const int LOG_LIMIT = 100;

    public const int MAX_TESTS = 5;

    public const int TEST_DECAY_SECONDS = 900;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly ObjectRepository $repository,
        private readonly Throttle $throttle,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render template, active menu item, context */
    public function log(Closure $render): Response
    {
        return $render('mail', 'mail', [
            'title' => 'mail.log.title',
            'entries' => $this->mailer->log(self::LOG_LIMIT),
            'configured' => $this->mailer->isConfigured(),
            'transport' => $this->mailer->description(),
            'from' => $this->mailer->from(),
        ]);
    }

    public function test(Request $request, Actor $actor): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = Response::redirect($request->basePath . $this->access->path('system'), 303);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        $user = $actor->id === null ? null : $this->repository->find($actor->id);
        if ($user === null || !$user->has(Identifiable::class)) {
            throw new HttpException(403, 'error.forbidden');
        }
        $key = 'mail-test|' . $actor->id;
        if ($this->throttle->tooManyAttempts($key, self::MAX_TESTS) || $this->throttle->hit($key, self::TEST_DECAY_SECONDS) > self::MAX_TESTS) {
            $this->flash->add(Flash::DANGER, new Message('mail.test.too_many', ['minutes' => max(1, (int) ceil($this->throttle->availableIn($key) / 60))]));

            return $back;
        }
        $email = $user->as(Identifiable::class)->email();
        $result = $this->mailer->send($email, 'test', ['sent_at' => gmdate('Y-m-d H:i:s') . ' UTC'], UserService::nameOf($user));
        $this->flash->add(
            $result->sent() ? Flash::SUCCESS : Flash::DANGER,
            new Message('mail.test.' . $result->status, ['email' => $email, 'error' => (string) $result->error]),
        );

        return $back;
    }
}

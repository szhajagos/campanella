<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Capability\Submitted;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Model\CampanellaObject;
use Campanella\Security\Csrf;
use Campanella\Service\SubmissionService;
use Closure;

/**
 * The admin's pages of the forms' messages (since 0.1.5), for those who may see
 * them (administrators):
 *
 *   /admin/submission?page=N&unread=1     the messages, newest first (only the unread ones)
 *   /admin/submission/<id>                one message; opening it marks it read
 *   POST /admin/submission/<id>/unread    marks it unread again
 *   POST /admin/submission/<id>/delete    deletes it
 *
 * Read-only: a message cannot be created or edited here.
 */
final class SubmissionPages
{
    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /** Whether the actor may see the messages (the menu item). */
    public function canView(Actor $actor): bool
    {
        return $this->submissions->canView($actor);
    }

    /** The unread messages the actor may see (the menu's badge). */
    public function unreadCount(Actor $actor): int
    {
        return $this->submissions->canView($actor) ? $this->submissions->unreadCount($actor) : 0;
    }

    /**
     * @param list<string> $segments The path after /admin/submission
     * @param Closure(string, string, array<string, mixed>): Response $render template, active menu item, context
     */
    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        if (!$this->submissions->canView($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }

        return match (true) {
            $segments === [] => $this->listing($request, $actor, $render),
            count($segments) === 1 => $this->view($actor, $this->find($actor, $segments[0]), $render),
            count($segments) === 2 && in_array($segments[1], ['unread', 'delete'], true) => $this->action($request, $actor, $this->find($actor, $segments[0]), $segments[1]),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function listing(Request $request, Actor $actor, Closure $render): Response
    {
        $unreadOnly = $request->queryString('unread') === '1';
        $page = $request->queryString('page');
        $page = preg_match('/^[1-9]\d{0,5}$/', $page) === 1 ? (int) $page : 1;
        $result = $this->submissions->page($actor, $page, $unreadOnly);
        if ($page > 1 && $result->isEmpty()) {
            throw HttpException::notFound();
        }

        return $render('submissions', SubmissionService::BLUEPRINT, [
            'title' => 'submissions.title',
            'submissions' => array_map(self::row(...), $result->items),
            'total' => $result->total ?? 0,
            'page' => $result->currentPage(),
            'pages' => $result->pageCount(),
            'unread_only' => $unreadOnly,
            'unread' => $this->submissions->unreadCount($actor),
        ]);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function view(Actor $actor, CampanellaObject $submission, Closure $render): Response
    {
        $wasRead = $submission->as(Submitted::class)->isRead();
        $this->submissions->mark($actor, $submission, true);

        return $render('submission', SubmissionService::BLUEPRINT, [
            'title' => 'submissions.one',
            'submission' => self::row($submission) + ['was_read' => $wasRead],
        ]);
    }

    private function action(Request $request, Actor $actor, CampanellaObject $submission, string $action): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $list = $request->basePath . $this->access->path(SubmissionService::BLUEPRINT);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return Response::redirect($list . '/' . $submission->id(), 303);
        }
        if ($action === 'unread') {
            $this->submissions->mark($actor, $submission, false);
            $this->flash->add(Flash::SUCCESS, 'submissions.marked_unread');
        } else {
            $this->submissions->delete($actor, $submission);
            $this->flash->add(Flash::SUCCESS, 'submissions.deleted');
        }

        return Response::redirect($list, 303);
    }

    private function find(Actor $actor, string $id): CampanellaObject
    {
        if (preg_match('/^[1-9]\d{0,18}$/', $id) !== 1) {
            throw HttpException::notFound();
        }

        return $this->submissions->find($actor, (int) $id) ?? throw HttpException::notFound();
    }

    /**
     * A message for the templates (they cannot read its hidden fields themselves).
     *
     * @return array{id: int, name: string, email: string, subject: string, message: string, form: string, created: string, read: bool}
     */
    private static function row(CampanellaObject $submission): array
    {
        $lens = $submission->as(Submitted::class);

        return [
            'id' => (int) $submission->id(),
            'name' => (string) $submission->get('title'),
            'email' => $lens->email(),
            'subject' => $lens->subject(),
            'message' => $lens->message(),
            'form' => $lens->form(),
            'created' => $submission->created()->format(DATE_ATOM),
            'read' => $lens->isRead(),
        ];
    }
}

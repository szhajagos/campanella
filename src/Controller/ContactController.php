<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Http\SitePaths;
use Campanella\I18n\Translator;
use Campanella\View\Presentation;
use Campanella\Webform\ContactForm;
use Campanella\Webform\ContactResult;

/**
 * The contact form's page (since 0.1.5), at `paths.contact` (`/contact`):
 *
 *   GET  /contact          the form
 *   POST /contact          sends it: to /contact?sent=1 ("thank you"), or the form again
 *                          with the errors (400: expired, 422: invalid or too fast, 429: too many)
 *   GET  /contact?sent=1   the thank-you message
 *
 * The form can also be put on any page with {{ contact_form() }}; it is always sent here.
 * 404 while `contact.enabled` is false.
 */
final class ContactController implements Controller
{
    public function __construct(
        private readonly ContactForm $form,
        private readonly Presentation $presentation,
        private readonly Translator $translator,
        private readonly SitePaths $paths = new SitePaths(),
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        if (!$this->form->isEnabled()) {
            throw HttpException::notFound();
        }
        if (!$request->isPost()) {
            return $request->queryString('sent') === '1' ? $this->page($request, sent: true) : $this->page($request);
        }
        $result = $this->form->submit($request);
        if ($result->looksSent()) {
            return Response::redirect($request->basePath . $this->paths->get('contact') . '?sent=1', 303);
        }

        return $this->page($request, result: $result, status: match ($result->status) {
            ContactResult::EXPIRED => 400,
            ContactResult::TOO_MANY => 429,
            default => 422,
        });
    }

    private function page(Request $request, bool $sent = false, ?ContactResult $result = null, int $status = 200): Response
    {
        return Response::html($this->presentation->render('page/contact.html.twig', [
            'title' => $this->translator->translate('contact.title'),
            'sent' => $sent,
            'form' => $sent ? null : [
                'values' => $result->values ?? array_fill_keys(array_keys(ContactForm::FIELDS), ''),
                'errors' => $result->errors ?? [],
                'message' => $result?->message,
                'max' => ContactForm::FIELDS,
            ] + $this->form->fields($request),
        ]), $status);
    }
}

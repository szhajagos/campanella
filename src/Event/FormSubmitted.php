<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;

/**
 * A message arrived through a form of the site (since 0.1.5), e.g. the contact form;
 * dispatched by SubmissionService::submit() after saving it. Its actor is null (a
 * visitor sent it). The message's text is read from the object, never put into the
 * summary (which may end up in a log or an e-mail subject).
 */
final class FormSubmitted extends Event
{
    public function __construct(public readonly CampanellaObject $submission, public readonly string $form)
    {
        parent::__construct(null);
    }

    #[\Override]
    public function summary(): Message
    {
        return new Message('event.form_submitted', ['form' => $this->form, 'id' => (int) $this->submission->id()]);
    }
}

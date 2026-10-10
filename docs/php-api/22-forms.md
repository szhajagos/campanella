# 22. Forms and their messages

Since 0.1.5 the site has a contact form. Its messages are objects, kept in the
database and read in the admin; only administrators see them. 0.1.5 is built
in parts: this chapter grows with them (the e-mail notification and the
deletion of old messages come next).

| Class | Namespace | What it does |
|---|---|---|
| `Submitted` | `Campanella\Capability` | The capability of a form's message: e-mail address, subject, message, form, read or not |
| `SubmissionService` | `Campanella\Service` | Saving a new message; listing, reading, marking and deleting them |
| `SubmissionPages` | `Campanella\Admin` | The admin's *Submissions* pages |
| `ContactForm`, `ContactResult` | `Campanella\Webform` | The contact form: checking a sending, saving it |
| `ContactController` | `Campanella\Controller` | The contact page (`/contact`) |

## The messages (`submission`)

A message is an object of the `submission` Blueprint (`config/blueprints.php`):
the sender's name is its title (`Titled`), the rest is `Submitted`'s.

`Campanella\Capability\Submitted` · **Public** · capability `submitted` (requires `Titled`)

| Field | Type | |
|---|---|---|
| `sender_email` | String (254) | Required; a valid address, stored in lowercase |
| `subject` | String (200) | Optional; control characters (line breaks too) become spaces |
| `message` | Text | Required; at most `MAX_MESSAGE` (5,000) characters; line breaks stay, other control characters go |
| `form` | String (64) | Which form it came from (`contact`); lowercase letters, digits, `_` |
| `read_at` | DateTime | When an administrator first opened it; null: unread |

All but `form` are **hidden fields**: templates cannot read them (personal
data), only code through the capability. **The sender's IP address is not
stored.**

| Method | |
|---|---|
| `email()`, `subject()`, `message()`, `form()` | The values |
| `readAt(): ?DateTimeImmutable`, `isRead(): bool` | |
| `markRead(?DateTimeImmutable $at = null): void`, `markUnread(): void` | |

Constants: `MAX_MESSAGE` (5000), `MAX_SUBJECT` (200), `MAX_EMAIL` (254).

### Only administrators see them

The default access policy keeps the objects of `submitted` (by default; see
`DefaultPolicy::ADMINISTRATORS_ONLY`, [chapter 5](05-access.md#defaultpolicy))
from everyone else: no query returns them to a visitor or an editor, and no
operation is allowed on them. A custom policy decides for itself.

## SubmissionService

`Campanella\Service\SubmissionService` · **Public** · container: `SubmissionService::class`

| Method | |
|---|---|
| `check(string $name, string $email, string $subject, string $message): array` | The problems of a message, without saving it: field => message (`title` is the name); empty: it can be saved |
| `submit(string $name, string $email, string $subject, string $message, string $form = 'contact'): CampanellaObject` | Saves a visitor's message (no actor: anyone may send one). Name (at most 100 characters), e-mail address and message are required; `ValidationException` on `title`, `sender_email`, `subject`, `message` |
| `canView(Actor $actor): bool` | Whether the actor may see the messages (the policy: by default administrators) |
| `page(Actor $actor, int $page = 1, bool $unreadOnly = false): ResultSet` | `PER_PAGE` (50) messages, newest first, with the total |
| `unreadCount(Actor $actor): int` | |
| `find(Actor $actor, int $id): ?CampanellaObject` | A message the actor may see |
| `mark(Actor $actor, CampanellaObject $submission, bool $read): void` | Read or unread; `AccessDeniedException` if not allowed |
| `delete(Actor $actor, CampanellaObject $submission): void` | Deletes it for good; `AccessDeniedException` if not allowed |

Constants: `BLUEPRINT` (`'submission'`), `MAX_NAME` (100), `PER_PAGE` (50).

```php
$submissions = $container->get(SubmissionService::class);
$message = $submissions->submit('Kiss Anna', 'anna@example.hu', 'Kérdés', 'Mikor vannak nyitva?');
$submissions->unreadCount(Actor::system());   // 1
```

## In the admin

`Campanella\Admin\SubmissionPages` · **Internal** · called by the
`AdminController` for `/admin/submission…` (`handle(Request, Actor, array $segments, Closure $render)`),
for those who may see the messages (`canView(Actor)`, `unreadCount(Actor)`: the menu)

| Page | |
|---|---|
| `/admin/submission` | The messages, newest first: sender, e-mail address, subject (or the beginning of the message), time; the unread ones bold, marked *new*. `?unread=1`: only the unread ones; `?page=N` |
| `/admin/submission/<id>` | One message, as plain text (escaped, line breaks kept). **Opening it marks it read.** *Reply by e-mail* (a `mailto:` link with `Re: <subject>`) |
| `POST /admin/submission/<id>/unread` | Unread again |
| `POST /admin/submission/<id>/delete` | Deleted for good (after a confirmation, both with a CSRF token) |

Messages cannot be created or edited in the admin. The sidebar shows
*Submissions* with the number of unread ones to those who may see them; the
dashboard counts them, but does not list them among the latest content.
Others get 403. Templates: `admin/submissions.html.twig`,
`admin/submission.html.twig`.

## The contact form

*Since 0.1.5 (part 2).* On its own page, at `paths.contact` (`/contact`; a
setting like the login's: `'paths' => ['contact' => '/kapcsolat']`), and on any
page of a theme with `{{ contact_form() }}`. Its fields: name, e-mail address,
subject (optional) and message. It is always sent to the contact page, which
shows the result.

| Route | |
|---|---|
| `GET /contact` | The form (`page/contact.html.twig`, with `page/_contact_form.html.twig`) |
| `POST /contact` | Saved: 303 to `/contact?sent=1`. Otherwise the form again, with what was entered: 400 (an expired form or CSRF token), 422 (invalid fields, or sent too fast), 429 (too many messages) |
| `GET /contact?sent=1` | *Thank you, your message has arrived* |

The page is ordinary content: search engines may index it, and it is in
`sitemap.xml`. Showing the form starts a session (a cookie, for the CSRF
token), as the login page does.

**Protections**, in this order:

1. **CSRF token** (the session's): another site cannot send it in a visitor's
   name.
2. **Guards** (`contact.guards`, the same `LoginGuard` classes as for logging
   in; by default the honeypot): a bot that fills in the hidden field is told
   *sent*, and nothing is saved.
3. **The time the form was shown**, in a hidden field (`_ts`) signed with the
   session's CSRF token (HMAC-SHA-256), so it cannot be forged or carried to
   another session; older than a day (`MAX_AGE`), the form has expired.
4. **The fields**: name (at most 100 characters), e-mail address (a valid one),
   message (at most 5,000) required, subject at most 200; shown again with
   their errors.
5. **Sent too fast**: sooner than `contact.min_seconds` (3) after the form was
   shown, it is taken for a bot's, and shown again (*please check it, and send
   it again*). The fields are checked first, so a quick human sees what to fix.
6. **Limits** (`Throttle`): 5 messages per IP address (IPv6: /64) and 3 per
   sender's e-mail address in an hour. Only messages that would be saved count,
   not the corrections of a typo.

Settings (`config/app.php`, override in `config/local.php`):

| Key | Default | |
|---|---|---|
| `contact.enabled` | `true` | `false`: `/contact` answers 404, and `contact_form()` shows nothing |
| `contact.min_seconds` | `3` | The shortest time between showing and sending the form |
| `contact.guards` | `[HoneypotGuard::class]` | `LoginGuard` classes |
| `paths.contact` | `'/contact'` | The page's path |

`Campanella\Webform\ContactForm` · **Public** · container: `ContactForm::class`

| Member | |
|---|---|
| `isEnabled(): bool` | |
| `fields(Request $request): array` | What the form's template needs besides the values: `ts` (the signed time) and `guard_fields`. Starts the session |
| `submit(Request $request): ContactResult` | Checks a sending, as above, and saves it (`SubmissionService::submit()`, form `contact`) |

Constants: `FORM` (`'contact'`), `TIME_FIELD` (`'_ts'`), `MIN_SECONDS` (3),
`MAX_AGE` (86400), `MAX_PER_IP` (5), `MAX_PER_ADDRESS` (3), `DECAY_SECONDS`
(3600), `FIELDS` (field => longest value).

`Campanella\Webform\ContactResult` · **Public** · `final readonly class`:
`status` (`SENT`, `IGNORED` (a bot), `INVALID`, `TOO_FAST`, `TOO_MANY`,
`EXPIRED`), `values` (what was entered), `errors` (field => message),
`message` (`?Message`), `submission` (the saved object); `looksSent(): bool`
(`SENT` or `IGNORED`: the sender is told the same).

`Campanella\Controller\ContactController` · **Internal** · handler: `contact`.

### In a theme

```twig
{# e.g. on the About page's template #}
<h2>{{ t('contact.title') }}</h2>
{{ contact_form() }}
```

The form's template is `page/_contact_form.html.twig` (variables: `values`,
`errors`, `message`, `max`, `ts`, `guard_fields`); a theme may override it,
keeping the hidden fields (`csrf_field()`, `_ts`, `guard_fields`).

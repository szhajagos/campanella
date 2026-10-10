# 22. Forms and their messages

Since 0.1.5 the site has a contact form. Its messages are objects, kept in the
database and read in the admin; only administrators see them. 0.1.5 is built
in parts: this chapter grows with them.

| Class | Namespace | What it does |
|---|---|---|
| `Submitted` | `Campanella\Capability` | The capability of a form's message: e-mail address, subject, message, form, read or not |
| `SubmissionService` | `Campanella\Service` | Saving a new message; listing, reading, marking and deleting them |
| `SubmissionPages` | `Campanella\Admin` | The admin's *Submissions* pages |

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
| `submit(string $name, string $email, string $subject, string $message, string $form = 'contact'): CampanellaObject` | Saves a visitor's message (no actor: anyone may send one). Name (at most 100 characters), e-mail address and message are required; `ValidationException` on `title`, `sender_email`, `subject`, `message` |
| `canView(Actor $actor): bool` | Whether the actor may see the messages (the policy: by default administrators) |
| `page(Actor $actor, int $page = 1, bool $unreadOnly = false): ResultSet` | `PER_PAGE` (50) messages, newest first, with the total |
| `unreadCount(Actor $actor): int` | |
| `find(Actor $actor, int $id): ?CampanellaObject` | A message the actor may see |
| `mark(Actor $actor, CampanellaObject $submission, bool $read): void` | Read or unread; `AccessDeniedException` if not allowed |
| `delete(Actor $actor, CampanellaObject $submission): void` | Deletes it for good; `AccessDeniedException` if not allowed |

Constants: `BLUEPRINT` (`'submission'`), `PER_PAGE` (50).

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

# 21. Events and e-mail

Since 0.1.3 Campanella says what happened (**events**: an article was
published, a user was created), and operations can be bound to them in the
configuration (**actions**). It can also **send e-mail**, through
[symfony/mailer](https://symfony.com/doc/current/mailer.html) (MIT): the
forgotten password (0.1.4) and the contact form (0.1.5) build on both.

| Class | Namespace | What it does |
|---|---|---|
| `Event` and its kinds | `Campanella\Event` | Something that happened, after it was saved |
| `EventDispatcher` | `Campanella\Event` | Passes events to the listeners and actions bound to them |
| `Action` | `Campanella\Event` | An operation bound to events in the configuration |
| `MailAdministrators` | `Campanella\Event\Action` | A built-in action: tells the administrators by e-mail |
| `Mailer`, `MailResult` | `Campanella\Mail` | Sending e-mails made from templates, the log |
| `MailCheck` | `Campanella\Mail` | The System page's *E-mail* lines |
| `MailPages` | `Campanella\Admin` | The e-mail log and the test e-mail in the admin |

## Events

An event is an object of a class that extends `Event`, made after the
operation was saved:

| Event | Dispatched by | Properties |
|---|---|---|
| `ObjectCreated` | `ObjectService::create()` | `object` |
| `ObjectUpdated` | `ObjectService::update()` | `object` |
| `ObjectPublished` | `ObjectService::create(publish: true)`, `publish()` | `object`, `publishedAt`; `isScheduled()`: visible only later |
| `ObjectUnpublished` | `ObjectService::unpublish()` | `object` |
| `ObjectDeleted` | `ObjectService::delete()` | `object` (its ID is still readable; it cannot be loaded any more) |
| `UserCreated` | `UserService::create()` | `user` |
| `PasswordChanged` | `UserService::setPassword()`, `changeOwnPassword()` | `user`, `byAdministrator` (never the password); its `actor` is the administrator, or the user (on the profile) |

Every event has `actor` (`?Actor`: who did it; null for the system),
`occurredAt` (UTC), `summary(): Message` (a translatable sentence, e.g.
`event.object_published`: *Published: {title}*) and `name(): string` (the
class's short name). The object events extend `ObjectEvent`, which adds
`title(): string` (the title, or `#<id>`).

`ObjectPublished` happens when an object is published, also for a later
time; that the scheduled time arrives is not an event yet (it needs a
scheduler).

Saving through the `ObjectRepository` directly (e.g. `seed`, migrations)
dispatches nothing: events belong to the operations of the services.

## The dispatcher

`Campanella\Event\EventDispatcher` · **Public** · container: `EventDispatcher::class`

| Method | |
|---|---|
| `listen(string $eventClass, Action\|Closure $listener): void` | A listener of the class, or of every class extending it (`ObjectEvent`: all object events; `Event`: all) |
| `bind(array $bindings, Closure $make): list<string>` | The `events` setting: event class => list of Action classes; an action is made (`$make`, by default `Action::create()`) on its first event. A wrong entry (not an event or Action class) is skipped and logged, and returned as a problem: the site keeps working, and the System page shows an error |
| `problems(): list<string>` | The problems `bind()` found |
| `dispatch(Event $event): void` | Calls the listeners, in the order they were added (whatever class they listen to) |
| `hasListeners(string $eventClass): bool` | Whether anything listens to it |

Listeners run synchronously, after the operation was saved. **A failing
listener is logged and skipped**: the operation already happened, and the
other listeners still run. (A slow listener slows the request down; the
`Mailer` limits the wait for a mail server, see below. A queue may come later.)

```php
$container->get(EventDispatcher::class)->listen(ObjectPublished::class, static function (ObjectPublished $event): void {
    error_log('Published: ' . $event->title());
});
```

## Actions

An action is code, its binding is configuration (`config/local.php`):

```php
use Campanella\Event\Action\MailAdministrators;
use Campanella\Event\ObjectPublished;
use Campanella\Event\UserCreated;

'events' => [
    ObjectPublished::class => [MailAdministrators::class],
    UserCreated::class => [MailAdministrators::class],
],
```

`Campanella\Event\Action` · **Public** · `interface`

```php
public static function create(Container $container): self;   // with the services it needs
public function handle(Event $event): void;
```

`Campanella\Event\Action\MailAdministrators` · **Public**: e-mails every
active administrator (at most `MAX_RECIPIENTS`, 50) about the event, with the
`event` template: its summary, who did it, when, and a link to the object in
the admin (if the site's address is set). The one who did it gets none (so
an administrator changing their own password is not mailed about it).
Nothing happens while e-mail is not set up.

## Sending e-mail

### Settings

Only in the configuration (`config/local.php` or environment variables),
never in the admin, because the mail server's address may hold a password:

```php
'mail' => [
    'dsn' => 'smtp://user:password@smtp.example.hu:587',
    'from' => 'noreply@example.hu',
],
```

| Key | Default | |
|---|---|---|
| `mail.dsn` | `''` (`CAMPANELLA_MAIL_DSN`) | Where e-mails go. `smtp://user:password@host:587` (STARTTLS when the server offers it), `smtps://user:password@host:465` (TLS), `native://default` (PHP's own settings: `sendmail_path`, as PHP's `mail()` uses them). Special characters of the user name and password are URL-encoded (`@` → `%40`) |
| `mail.from` | `''` (`CAMPANELLA_MAIL_FROM`) | The sender's address. Use an address of the site's own domain, which the mail server may send for (SPF, DKIM), or the e-mails land in spam |
| `mail.from_name` | `''` | The sender's name; empty: the site's name |
| `mail.log_days` | `90` | How long the log keeps the entries |
| `mail.timeout` | `10` | Seconds to wait for an SMTP server (instead of PHP's `default_socket_timeout`, 60) |

**Without both `dsn` and `from` nothing is sent**: the System page warns
(*E-mail → Sending: not set up*). An invalid `dsn` or `from` is an error on
the System page (its message never shows the password).

For development, the Docker setup (`compose.yaml`) has
[Mailpit](https://mailpit.axllent.org/): every e-mail the site sends ends up
at `http://localhost:8025` (reachable from the same computer only), none
reaches anyone.

### Templates

An e-mail is a template, `templates/mail/<name>.txt.twig`, with two blocks:

```twig
{% block subject %}{{ t('mail.test.subject', {site: site.name}) }}{% endblock %}
{% block body %}{% autoescape false %}
{{ to_name ? t('mail.greeting', {name: to_name}) : t('mail.greeting_anonymous') }}

{{ t('mail.test.body', {site: site.name}) }}
{% include 'mail/_footer.txt.twig' %}
{% endautoescape %}{% endblock %}
```

- The text is plain text: `{% autoescape false %}`, so `&` stays `&`.
- If `mail/<name>.html.twig` exists too, its `body` block becomes the HTML
  part (escaped as usual).
- Texts come from the language files (`t()`), so an e-mail is translated like
  the site; `site` and the other globals are there, plus `to` and `to_name`.
- A theme overrides a template by having its own `mail/<name>.txt.twig`.
- The subject is one line, at most 200 characters (`Mailer::MAX_SUBJECT`).

Built-in templates: `test` (the System page's test e-mail), `event`
(`MailAdministrators`), `_footer` (included by both).

### Mailer

`Campanella\Mail\Mailer` · **Public** · container: `Mailer::class`

| Member | |
|---|---|
| `static fromConfig(array $config, Closure $twig, Closure $fromName, ?Connection $db = null): self` | From the `mail` settings; `$twig` gives the templates' environment, `$fromName` the sender's name (both lazy) |
| `send(string $to, string $template, array $context = [], string $toName = ''): MailResult` | Renders and sends the template, and logs it. **Never throws** for a failure to send (an invalid address, a broken template, a mail server error): it is the result. `InvalidArgumentException` only for an invalid template name (lowercase letters, digits, `_`) |
| `isConfigured(): bool` | Whether e-mails can be sent |
| `configError(): ?string` | Why the settings are not usable, if they are given but wrong |
| `description(): string` | The transport for people: `smtp://smtp.example.hu:587` (never the user name or password) |
| `from(): string` | The sender's address |
| `log(int $limit = 50): list<array>` | The latest log entries, newest first: `created_at` (UTC), `recipient`, `template`, `subject`, `status`, `error` |
| `static describeDsn(string $dsn): string` | `smtp://user:pass@host:587?x` → `smtp://host:587` |

Every e-mail gets `Auto-Submitted: auto-generated` (no out-of-office replies to
it). **After a mail server failure, the other e-mails of the same request are
not tried** (logged as failed, *not tried: the mail server failed earlier in
this request*): a server that does not answer delays a request once
(`mail.timeout`), not once for every recipient. A mail server's error message is logged with any `user:password@` in it
replaced by `***@`.

`Campanella\Mail\MailResult` · **Public** · `final readonly class`: `status`
(`MailResult::SENT`, `FAILED`, `NOT_CONFIGURED`), `error` (`?string`),
`sent(): bool`.

```php
$result = $container->get(Mailer::class)->send('anna@example.hu', 'test', ['sent_at' => 'now'], 'Anna');
if (!$result->sent()) {
    // e.g. tell the user that the e-mail could not be sent
}
```

### The log

The `mail_log` table (schema version 9): the time, the recipient, the
template, the subject, the result and the error of every e-mail, **never its
text**. Entries older than `mail.log_days` are deleted when the next e-mail is
logged.

### In the admin

`Campanella\Admin\MailPages` · **Internal**, for the System page's roles
(`log(Closure $render): Response`, `test(Request $request, Actor $actor): Response`):

| Page | |
|---|---|
| `/admin/system/mail` | The latest `LOG_LIMIT` (100) log entries, under *System → E-mail* |
| `POST /admin/system/mail-test` | A test e-mail (the `test` template) **to the logged-in user's own address only**, so the page cannot send mail to anyone else; at most `MAX_TESTS` (5) in `TEST_DECAY_SECONDS` (15 minutes) |

`Campanella\Mail\MailCheck` · **Internal**: `static checks(Mailer $mailer): Closure`,
the System page's *E-mail* group: *Sending* (not set up: warning; invalid:
error; set up: the transport, with the test e-mail button), *Sender*, and the
*Latest e-mail* (a failure: warning, with its error).

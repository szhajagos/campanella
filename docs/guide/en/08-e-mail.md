# 8. E-mail

Campanella sends e-mail for the forgotten password and about the contact
form's messages. For that you tell it which mail server to send through.
Until then no e-mail goes out, and the System page warns.

## What you need

Your host's mail details. At most hosts you can create a mailbox in the
control panel (e.g. `noreply@example.com`), and find the outgoing mail
server's (SMTP) details:

- the server's address (e.g. `mail.example.com`),
- the port (usually **587**, or **465**),
- the user name (usually the full e-mail address) and the password.

**The sender should be on the site's own domain** (e.g. `noreply@example.com`
for an `example.com` site), and an address the mail server may send for.
Otherwise the e-mails may land in the recipients' spam folders. Hosts usually
set up the domain's mail records (SPF, DKIM) by themselves; if not, the host
helps.

## Setting it up

The settings go into `config/local.php`, not into the admin: the mail
server's password does not belong in the database. Add this to the array
(e.g. after the database settings):

```php
'mail' => [
    'dsn' => 'smtp://noreply%40example.com:the-mailbox-password@mail.example.com:587',
    'from' => 'noreply@example.com',
    'from_name' => 'Example Ltd',
],
```

- `dsn` holds the server's details in one line:
  `smtp://user:password@server:port`.
  - Port 465 needs `smtps://`: `smtps://user:password@server:465`.
  - Special characters in the user name or password are written encoded:
    `%40` for `@`, `%3A` for `:`, `%2F` for `/`, `%23` for `#`. In the
    example the user name is `noreply@example.com`, hence the `%40`.
  - If the host gives no SMTP server, `'dsn' => 'native://default'` uses
    PHP's own mail sending (it does not work on every host).
- `from` is the sender's address.
- `from_name` is the sender's name as recipients see it. Left out, it is the
  site's name.

Save the file. The setting applies at once.

## Trying it

On **System → E-mail** (or in the E-mail part of the System page),
**Send a test e-mail to myself** sends a test e-mail to your own address:

![The test e-mail sent](../images/en/mail-test-sent.png)

If it does not arrive in a few minutes, look in the spam folder too. If it is
not there either, look at the log (below), and at the **E-mail** part of the
System page: it shows a wrong setting.

## The e-mail log

**System → E-mail** shows the e-mails the site sent:

![The e-mail log](../images/en/mail-log.png)

- The time, the recipient, the subject, and the kind of e-mail (in brackets:
  `test`, `password_reset`, `submission` …).
- The **Result**: **sent**; **failed**, with the mail server's error message;
  or **not set up**, if e-mail was not set up then.
- The log keeps entries for 90 days, never the e-mails' text.

"Sent" means the mail server accepted the e-mail. Whether it arrived depends
on the recipient's mail (spam folder, a full mailbox).

## Common problems

| What you see | Cause, what to do |
|---|---|
| On the System page: "Sending: not set up" | `config/local.php` has no `mail` part, or `dsn` or `from` is missing. |
| On the System page: "The mail settings in config/local.php are not valid" | The `dsn` is malformed (e.g. a typo in `smtp://`), or `from` is not an e-mail address. |
| "failed" in the log, with an authentication error | Wrong user name or password, or a special character not encoded. |
| "failed" in the log, with a connection error or a timeout | Wrong server or port. Try the other one (587 with `smtp://`, 465 with `smtps://`). Some hosts allow outgoing mail only through their own server. |
| Sent, but it does not arrive | Look in the spam folder. The sender should be on the site's domain (see above). |

---

Previous: [7. The contact form and privacy](07-contact-form.md) · Next:
[9. Backups and upgrades](09-backups-and-upgrades.md) · [Contents](README.md)

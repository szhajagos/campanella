# 10. When something does not work

This chapter collects the most common problems, with their causes and what to
do. When something does not work, always look at the **System** page first
(chapter 2): it shows most setting problems, with what to do.

## The site does not show

| What you see | Cause, what to do |
|---|---|
| A blank page, or "An internal error occurred." (error 500) | The details are in the host's PHP error log (in the host's control panel, often called "Error log"). Campanella writes every error there. A common cause is a typo in `config/local.php` (e.g. a missing comma or quote), or an upload that stopped halfway. Do not turn on the debug mode for this: the log shows the error too. |
| "The site is being upgraded." | A new version was uploaded that changes the database too, but the upgrade has not run yet. Log in, and run it on the `/admin/upgrade` page (chapter 9). |
| "Campanella is not installed yet." | The database is empty, or `config/local.php` points to another database (or another `prefix`). Before installing, this is fine (chapter 1). If the site worked before, check the database settings in `local.php`. |
| "The page was not found." | The address has a typo, or the article is still a draft (visitors do not see it). Logged in, you see drafts too: so look at it logged out, or in a private window. A changed path's old address leads on by itself, unless you deleted its redirect (chapter 3). |
| "You do not have permission to view this page." | Editors manage the content only; the other parts need an administrator (chapter 6). |

## Logging in

| What you see | Cause, what to do |
|---|---|
| "Invalid e-mail address or password." | Check the address and the password (upper and lower case matter). You see the same if your account is blocked: ask an administrator. |
| "Too many failed attempts." | After five wrong passwords, wait up to 15 minutes. This protects your account against guessing. If you do not remember the password, ask for a new one (chapter 6). |
| The new password's link does not arrive | Look in the spam folder. If e-mail is not set up (chapter 8), the link is not even sent: another administrator can set a new password for you. |
| Logged out unexpectedly | Campanella logs you out after two hours of inactivity, and at most 12 hours after logging in. A password change, blocking, or **Log out everywhere else** on another device logs you out too. |
| No administrator can log in | With a command line (SSH): `php bin/campanella user:password <e-mail address>` sets a new password (with `--activate`, it allows a blocked account again). Without one, your host or a developer can help. |

## Forms and content

| What you see | Cause, what to do |
|---|---|
| "The form has expired. Please try again." | The form was open for too long, or you logged out meanwhile (e.g. in another tab). Reload the page, and send it again. Copy a long text first, so it is not lost. |
| On upload: "The file is too large" | The image is larger than PHP allows. Make it smaller, or raise `upload_max_filesize` and `post_max_size` in the host's PHP settings (chapter 2, the System page). |
| On upload: "The image is too large; at most … megapixels" | Images may have at most 25 megapixels: make it smaller (chapter 5). |
| An image format cannot be uploaded | The image processor of the host's PHP (gd) does not know it. The **Images and files** part of the System page shows which format does not work; your host can turn it on. |
| A menu item does not show | See the table in [chapter 4](04-menus.md#when-the-menu-does-not-look-as-you-want). |
| The old look after an upgrade or a change | **Clear the template cache** on the System page, and Ctrl+F5 in the browser, help. |
| The search does not find a page | The page is a draft or scheduled; or the word is shorter than three letters, or a very common English word. After an upgrade, older pages must be added to the search first: the **Search index** row of the System page says if something is missing (chapter 2). |
| A field, a Blueprint or "Filter and sort by it" cannot be removed | A list uses it (in a condition, the order, a column or a filter): the message names it. Change or delete the list first (chapter 11). A Blueprint cannot be deleted while it has items either. |
| A list's page does not show | The list has no path, or the lists (**System → Lists**) show an error for it, e.g. because its Blueprint changed in the file. A list shows only published items. |
| Wrong addresses in share links or e-mails | The **Web address (URL)** under **System → Site settings** is not the site's current address (e.g. after turning on HTTPS or a domain change). Correct it (chapter 2). |

## The contact form and e-mail

| What you see | Cause, what to do |
|---|---|
| The visitor sees "The form was sent very quickly" | The form arrived within 3 seconds of opening it, and Campanella took it for a bot's. Sent again, it arrives (chapter 7). |
| The visitor sees "Too many messages." | At most 5 messages an hour can come from one address. The limit protects against unwanted messages; an hour later they can write again. |
| E-mails do not arrive | See the common problems in [chapter 8](08-e-mail.md#common-problems), and the e-mail log. |

## If you are stuck

- **The System page** gives a current picture: under the rows that show an
  error is what to do. When asking for help, it is worth sending too
  (Campanella never shows passwords or keys there).
- **Report bugs** on GitHub:
  [github.com/szhajagos/campanella/issues](https://github.com/szhajagos/campanella/issues).
  Describe what you did, what you expected, and what you saw instead; the
  System page shows Campanella's version. Never post a password, a key, or
  `local.php` publicly.
- **Do not report security problems publicly**, but as described in the
  [security policy](../../security.md#reporting-a-vulnerability), so they
  cannot be abused before they are fixed.

---

Previous: [9. Backups and upgrades](09-backups-and-upgrades.md) · Next:
[11. Your own kinds of content](11-own-content-types.md) · [Contents](README.md)

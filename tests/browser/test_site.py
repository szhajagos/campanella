"""The browser tests (since 0.2.0), run by tests/browser/run.sh against a fresh site.

What the 0.1.x series was checked by hand, in a real browser (Playwright, headless
Chromium): logging in and out, the lock-out, the forgotten password (the e-mail read
from the mail catcher), the session list, the contact form with its protections, the
admin's lists and forms, the Submissions, the System page. Every page is watched as
well: a JavaScript error, a Content-Security-Policy violation, a missing stylesheet or
script, an alert() that ran fails the test.

Environment: CAMPANELLA_TEST_URL (the site), CAMPANELLA_TEST_MAILS (the mail catcher's
folder), CAMPANELLA_TEST_HEADED=1 (the browser is shown). The users are the ones run.sh
creates. Exits with 1 if anything failed, listing every failure.
"""

import email
import email.policy
import os
import re
import sys
import time
import traceback
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

BASE = os.environ["CAMPANELLA_TEST_URL"].rstrip("/")
MAILS = os.environ["CAMPANELLA_TEST_MAILS"]
HEADED = os.environ.get("CAMPANELLA_TEST_HEADED") == "1"

ADMIN = ("browser-admin@example.test", "browser-admin-jelszo-1")
EDITOR = ["browser-editor@example.test", "browser-editor-jelszo-1"]   # the reset test changes the password
LOCKED = ("browser-lock@example.test", "browser-lock-jelszo-1")

# The contact form's MIN_SECONDS (3), and a little more.
CONTACT_WAIT = 3.3

failures = []
checks = 0
problems_seen = []   # console errors, failed subresources, dialogs: checked at the end


def check(ok, what):
    global checks
    checks += 1
    if not ok:
        failures.append(what)
        print("    ✘ " + what)
    return ok


def path_of(page):
    return urlsplit(page.url).path


# ---------------------------------------------------------------------------
# Pages, watched

visitors = 0


def new_context(browser, address=None):
    """A new browser context: another visitor, with its own cookies and IP address (the
    site trusts 127.0.0.1 as a proxy, so X-Forwarded-For is believed). Its pages are watched."""
    global visitors
    visitors += 1
    address = address or "198.51.100.%d" % visitors
    context = browser.new_context(locale="hu-HU", viewport={"width": 1200, "height": 900},
                                  extra_http_headers={"X-Forwarded-For": address})

    def watch(page):
        def on_console(message):
            # A main document's 4xx (a test asking for a forbidden page) is reported as a
            # failed resource too: the responses are watched separately, below.
            if message.type == "error" and "Failed to load resource" not in message.text:
                problems_seen.append("console error on %s: %s" % (page.url, message.text))

        def on_response(response):
            request = response.request
            if response.status >= 400 and request.resource_type not in ("document",):
                problems_seen.append("%s %s (%s, on %s)" % (response.status, request.url, request.resource_type, page.url))

        def on_dialog(dialog):
            problems_seen.append("a dialog opened on %s: %s" % (page.url, dialog.message))
            dialog.dismiss()

        page.on("console", on_console)
        page.on("pageerror", lambda error: problems_seen.append("JavaScript error on %s: %s" % (page.url, error)))
        page.on("response", on_response)
        page.on("dialog", on_dialog)

    context.on("page", watch)
    return context


def login(page, user, expect_success=True):
    page.goto(BASE + "/login")
    page.fill("#email", user[0])
    page.fill("#password", user[1])
    page.click("form[action$='/login'] button[type=submit]")
    page.wait_for_load_state("load")
    if expect_success:
        # Without a `return` target the login page leads to the front page.
        check(path_of(page) == "/" and page.locator(".alert-danger").count() == 0, "logging in as %s: ended on %s" % (user[0], page.url))
        check(logged_in(page), "logging in as %s: not logged in" % user[0])


def logged_in(page):
    """Whether the context's session is logged in: /admin opens (no redirect to the login page)."""
    response = page.goto(BASE + "/admin")
    return response is not None and response.status == 200 and path_of(page).startswith("/admin")


def session_cookie(context):
    cookies = [c for c in context.cookies(BASE) if c["name"] not in ("",)]
    # The session's cookie: the one the server sets with HttpOnly.
    for cookie in cookies:
        if cookie.get("httpOnly"):
            return cookie
    return None


def status_of(page, path):
    response = page.goto(BASE + path)
    return response.status if response is not None else 0


# ---------------------------------------------------------------------------
# Mails

def mails():
    """Every caught e-mail, oldest first."""
    result = []
    for name in sorted(f for f in os.listdir(MAILS) if f.endswith(".eml")):
        with open(os.path.join(MAILS, name), "rb") as file:
            result.append(email.message_from_binary_file(file, policy=email.policy.default))
    return result


def wait_for_mails(count, seconds=10):
    """Waits until at least `count` mails have arrived; returns them all."""
    end = time.time() + seconds
    while time.time() < end:
        caught = mails()
        if len(caught) >= count:
            return caught
        time.sleep(0.2)
    return mails()


def text_of(message):
    part = message.get_body(preferencelist=("plain",))
    return part.get_content() if part is not None else ""


# ---------------------------------------------------------------------------
# The tests

def test_security_headers(browser):
    context = new_context(browser)
    page = context.new_page()
    for path in ("/", "/login", "/contact"):
        response = page.goto(BASE + path)
        headers = response.headers
        check(response.status == 200, "%s: %s" % (path, response.status))
        check(headers.get("x-content-type-options") == "nosniff", "%s: no X-Content-Type-Options" % path)
        check("x-frame-options" in headers, "%s: no X-Frame-Options" % path)
        check("referrer-policy" in headers, "%s: no Referrer-Policy" % path)
        csp = headers.get("content-security-policy", "")
        script_src = next((d for d in csp.split(";") if d.strip().startswith("script-src")), "")
        check(script_src != "" and "'unsafe-inline'" not in script_src and "'unsafe-eval'" not in script_src,
              "%s: the Content-Security-Policy allows inline scripts, or has no script-src: %s" % (path, csp))
    response = page.goto(BASE + "/admin")
    check(path_of(page) == "/login", "/admin without logging in: ended on %s" % page.url)
    context.close()


def test_login_and_logout(browser):
    context = new_context(browser)
    page = context.new_page()

    # A wrong password: an error, and not logged in.
    login(page, (ADMIN[0], "rossz-jelszo-123"), expect_success=False)
    check(path_of(page) == "/login" and page.locator(".alert-danger").count() == 1, "a wrong password: no error shown")
    wrong_password = page.locator(".alert-danger").inner_text()
    check(page.input_value("#password") == "", "a wrong password: the password is shown again")
    check(not logged_in(page), "a wrong password logged in")

    # An unknown address gets the same answer (no telling which addresses exist).
    login(page, ("nincs-ilyen@example.test", "rossz-jelszo-123"), expect_success=False)
    check(page.locator(".alert-danger").inner_text() == wrong_password, "an unknown address gets another answer than a wrong password")

    # The honeypot filled in (as a bot does): refused, even with the right password.
    page.goto(BASE + "/login")
    page.fill("#email", ADMIN[0])
    page.fill("#password", ADMIN[1])
    page.eval_on_selector("input[name=website]", "e => e.value = 'http://spam.example'")
    page.click("form[action$='/login'] button[type=submit]")
    check(page.locator(".alert-danger").count() == 1, "the honeypot: no error shown")
    check(not logged_in(page), "the honeypot filled in, yet logged in")

    # No CSRF token (another site's form): refused.
    page.goto(BASE + "/login")
    page.fill("#email", ADMIN[0])
    page.fill("#password", ADMIN[1])
    page.eval_on_selector("form[action$='/login'] input[name=_csrf]", "e => e.remove()")
    page.click("form[action$='/login'] button[type=submit]")
    check(not logged_in(page), "logged in without a CSRF token")

    # Logging in: a new session id (no session fixation), and a safe cookie.
    page.goto(BASE + "/login")
    before = session_cookie(context)
    login(page, ADMIN)
    after = session_cookie(context)
    check(after is not None, "logged in, but no HttpOnly session cookie")
    if before is not None and after is not None:
        check(before["value"] != after["value"], "the session id did not change on logging in")
    if after is not None:
        check(after.get("sameSite") in ("Lax", "Strict"), "the session cookie's SameSite: %s" % after.get("sameSite"))

    # A GET to the logout address does not log out (only the form, with its token).
    page.goto(BASE + "/logout")
    check(logged_in(page), "a GET request logged out")

    # Logging out: the button; the old session id is worth nothing afterwards.
    page.goto(BASE + "/admin")
    old = session_cookie(context)
    page.click("form[action$='/logout'] button[type=submit]")
    page.wait_for_load_state("load")
    check(path_of(page) == "/", "after logging out: %s" % page.url)
    check(not logged_in(page), "still logged in after logging out")
    if old is not None:
        thief = new_context(browser)
        thief.add_cookies([{k: old[k] for k in ("name", "value", "domain", "path")}])
        check(not logged_in(thief.new_page()), "the old session cookie still works after logging out")
        thief.close()
    context.close()


def test_lockout(browser):
    context = new_context(browser)
    page = context.new_page()
    for _ in range(5):
        login(page, (LOCKED[0], "rossz-jelszo-123"), expect_success=False)
    login(page, LOCKED, expect_success=False)
    check(page.locator(".alert-danger").count() == 1 and not logged_in(page),
          "after 5 wrong passwords the right one still logs in (no lock-out)")
    # The lock-out is for that account from that address: from another address the
    # account still opens (so an attacker cannot lock its owner out), and from this
    # address another account does.
    login(page, ADMIN)
    elsewhere = new_context(browser)
    login(elsewhere.new_page(), LOCKED)
    elsewhere.close()
    context.close()

    # One address guessing at many accounts: after 20 attempts it is refused, even with
    # a right password.
    context = new_context(browser)
    page = context.new_page()
    for number in range(20):
        login(page, ("talalgatas-%d@example.test" % number, "rossz-jelszo-123"), expect_success=False)
    login(page, ADMIN, expect_success=False)
    check(page.locator(".alert-danger").count() == 1 and not logged_in(page),
          "after 20 attempts from one address a login still succeeds (no limit per address)")
    context.close()


def test_sessions(browser):
    first, second, third = new_context(browser), new_context(browser), new_context(browser)
    a, b, c = first.new_page(), second.new_page(), third.new_page()
    login(a, EDITOR)
    login(b, EDITOR)

    a.goto(BASE + "/admin/profile")
    rows = a.locator("#sessions tbody tr")
    check(rows.count() == 2, "the session list: %d sessions, expected 2" % rows.count())
    check(a.locator("#sessions .badge.text-bg-success").count() == 1, "the session list: the current session is not marked")
    # Ending the other session.
    a.locator("#sessions form[action$='/profile/sessions/end'] button[type=submit]").first.click()
    a.wait_for_load_state("load")
    check(not logged_in(b), "an ended session still works")
    check(logged_in(a), "ending another session logged out this one")

    # "End every other session".
    login(b, EDITOR)
    login(c, EDITOR)
    a.goto(BASE + "/admin/profile")
    check(a.locator("#sessions tbody tr").count() == 3, "the session list: expected 3 sessions")
    a.click("form[action$='/profile/sessions/others'] button[type=submit]")
    a.wait_for_load_state("load")
    check(not logged_in(b) and not logged_in(c), "ending every other session left one working")
    check(logged_in(a), "ending every other session logged out this one")

    # The list shows the current user's sessions only (the editor cannot end another user's).
    login(b, ADMIN)
    a.goto(BASE + "/admin/profile")
    check(a.locator("#sessions tbody tr").count() == 1, "the session list shows another user's session")
    for context in (first, second, third):
        context.close()


def test_password_reset(browser):
    victim_context, context = new_context(browser), new_context(browser)
    victim, page = victim_context.new_page(), context.new_page()
    login(victim, EDITOR)   # this session must end when the password is reset
    before = len(mails())

    page.goto(BASE + "/login")
    page.click("a[href$='/password-reset']")
    check(path_of(page) == "/password-reset", "the login page has no link to the forgotten password")

    # An unknown address: the same answer as a known one, and no e-mail.
    page.fill("#email", "nincs-ilyen@example.test")
    page.click("form[action$='/password-reset'] button[type=submit]")
    page.wait_for_load_state("load")
    unknown = page.locator("[role=status]").inner_text()
    page.goto(BASE + "/password-reset")
    page.fill("#email", EDITOR[0])
    page.click("form[action$='/password-reset'] button[type=submit]")
    page.wait_for_load_state("load")
    check(page.locator("[role=status]").inner_text() == unknown, "an unknown address gets another answer than a known one")

    caught = wait_for_mails(before + 1)
    time.sleep(1)   # no second e-mail may arrive
    caught = mails()
    check(len(caught) == before + 1, "the forgotten password: %d e-mails, expected 1" % (len(caught) - before))
    if len(caught) <= before:
        victim_context.close()
        context.close()
        return
    mail = caught[before]
    check(EDITOR[0] in str(mail["To"]), "the reset e-mail went to %s" % mail["To"])
    links = re.findall(r"https?://\S+/password-reset\?token=[0-9a-f]+", text_of(mail))
    check(len(links) == 1, "the reset e-mail has no link")
    if not links:
        victim_context.close()
        context.close()
        return
    link = links[0]

    page.goto(link)
    check("token" not in page.url, "the token stays in the address bar (and in the Referer): %s" % page.url)
    page.fill("#password", "rovid")
    page.fill("#password_again", "rovid")
    page.click("form[action$='/password-reset'] button[type=submit]")
    check(page.locator("#password.is-invalid").count() == 1, "a too short new password: no error")
    new_password = "browser-uj-jelszo-2"
    page.fill("#password", new_password)
    page.fill("#password_again", new_password)
    page.click("form[action$='/password-reset'] button[type=submit]")
    page.wait_for_load_state("load")
    check(path_of(page) == "/login" and page.locator(".alert-success").count() == 1, "after setting the new password: %s" % page.url)

    check(not logged_in(victim), "the sessions of before the reset still work")
    page.goto(link)
    check(page.locator(".alert-warning").count() == 1, "the reset link works a second time")
    login(page, EDITOR, expect_success=False)
    check(not logged_in(page), "the old password still works")
    EDITOR[1] = new_password
    login(page, EDITOR)
    victim_context.close()
    context.close()


def fill_contact(page, name, address, subject, message):
    page.fill("#contact-name", name)
    page.fill("#contact-email", address)
    page.fill("#contact-subject", subject)
    page.fill("#contact-message", message)


def test_contact_form(browser):
    context = new_context(browser)
    page = context.new_page()
    before = len(mails())

    # Too fast (a bot): shown again with the values, nothing saved.
    page.goto(BASE + "/contact")
    fill_contact(page, "Gyors Gépelő", "gyors@example.test", "Gyors", "Túl gyorsan küldöm.")
    page.click("form button[type=submit]")
    page.wait_for_load_state("load")
    check(page.locator(".alert-success").count() == 0 and page.input_value("#contact-name") == "Gyors Gépelő",
          "a too quick sending: not shown again with its values")

    # The honeypot: the bot is told "sent", nothing is saved.
    page.goto(BASE + "/contact")
    time.sleep(CONTACT_WAIT)
    fill_contact(page, "Robot", "robot@example.test", "Spam", "Vegyél tőlem.")
    page.eval_on_selector("input[name=website]", "e => e.value = 'http://spam.example'")
    page.click("form button[type=submit]")
    page.wait_for_load_state("load")
    check(page.locator(".alert-success").count() == 1, "the honeypot: the bot is not told 'sent'")

    # No CSRF token: refused, and the text is not shown again (another site's text on this page).
    page.goto(BASE + "/contact")
    time.sleep(CONTACT_WAIT)
    fill_contact(page, "Idegen Oldal", "idegen@example.test", "Idegen", "Más oldalról jövök.")
    page.eval_on_selector("form input[name=_csrf]", "e => e.remove()")
    page.click("form button[type=submit]")
    page.wait_for_load_state("load")
    check(page.locator(".alert-success").count() == 0, "a sending without a CSRF token was accepted")
    check("Más oldalról jövök." not in page.content(), "a sending without a CSRF token: its text is shown again")

    # A real message, with markup in it (it must stay text everywhere).
    page.goto(BASE + "/contact")
    time.sleep(CONTACT_WAIT)
    markup = "<img src=x onerror=alert(1)><script>alert(2)</script>"
    fill_contact(page, "Teszt Elek", "teszt.elek@example.test", "Kérdés", "Jó napot!\nEgy kérdésem van. " + markup)
    page.click("form button[type=submit]")
    page.wait_for_load_state("load")
    check(page.locator(".alert-success").count() == 1, "a real message was not accepted")

    # The notification: to the administrator, the reply to the sender, the sender's text not in the subject.
    caught = wait_for_mails(before + 1)
    time.sleep(1)
    caught = mails()
    new = caught[before:]
    check(len(new) == 1, "the contact form: %d e-mails, expected 1 (the honeypot's and the others' were sent too?)" % len(new))
    if new:
        mail = new[0]
        check(ADMIN[0] in str(mail["X-Envelope-To"]), "the notification went to %s, not the administrator" % mail["X-Envelope-To"])
        check("teszt.elek@example.test" in str(mail["Reply-To"]), "the notification's Reply-To: %s" % mail["Reply-To"])
        check("Kérdés" not in str(mail["Subject"]), "the notification's subject holds the sender's text: %s" % mail["Subject"])
        body = text_of(mail)
        check("Egy kérdésem van." in body and "/admin/submission/" in body, "the notification has no message or link")

    # The admin: exactly this one message, shown as text.
    admin_context = new_context(browser)
    admin = admin_context.new_page()
    login(admin, ADMIN)
    admin.goto(BASE + "/admin/submission")
    rows = admin.locator("table tbody tr")
    check(rows.count() == 1, "Submissions: %d messages, expected 1 (only the real one is saved)" % rows.count())
    link = admin.locator("table tbody a[href*='/admin/submission/']").first
    href = link.get_attribute("href") if rows.count() else None
    if href:
        link.click()
        admin.wait_for_load_state("load")
        check(markup in admin.locator("main").inner_text(), "the message's markup is not shown as text")
        check(admin.locator("main img[src=x]").count() == 0, "the message's markup became HTML")

        # Not for an editor.
        editor_context = new_context(browser)
        editor = editor_context.new_page()
        login(editor, EDITOR)
        check(status_of(editor, "/admin/submission") == 403, "an editor can see Submissions")
        check(status_of(editor, urlsplit(href).path) == 403, "an editor can open a message")
        editor_context.close()
    admin_context.close()
    context.close()


ADMIN_PAGES = [
    "/admin", "/admin/article", "/admin/article/new", "/admin/page", "/admin/image", "/admin/menu",
    "/admin/user", "/admin/user/new", "/admin/profile", "/admin/submission",
    "/admin/system", "/admin/system/settings", "/admin/system/mail",
    "/admin/system/blueprints", "/admin/system/lists", "/admin/system/model", "/admin/system/redirects",
]
ADMINISTRATORS_ONLY = ["/admin/user", "/admin/user/new", "/admin/submission", "/admin/system", "/admin/system/settings", "/admin/system/mail",
                       "/admin/system/blueprints", "/admin/system/lists", "/admin/system/model", "/admin/system/redirects"]


def test_admin_pages(browser):
    context = new_context(browser)
    page = context.new_page()
    login(page, ADMIN)
    for path in ADMIN_PAGES:
        status = status_of(page, path)
        check(status == 200, "%s as the administrator: %s" % (path, status))
        check(page.locator("h1").count() >= 1, "%s: no heading" % path)

    # A form: a new article, saved (the editor field is the HTML editor's).
    page.goto(BASE + "/admin/article/new")
    title = "Böngészős próbacikk"
    page.fill("#field-title", title)
    page.click("form.admin-form button[type=submit]:not([name])")
    page.wait_for_load_state("load")
    check(re.search(r"/admin/article/\d+$", path_of(page)) is not None, "saving a new article: ended on %s" % page.url)
    check(page.locator(".alert-success").count() == 1, "saving a new article: no message")
    page.goto(BASE + "/admin/article")
    check(page.get_by_text(title).count() >= 1, "the new article is not in the list")

    # The editor's image picker (since 0.2.7): its button opens a dialog of the uploaded images.
    page.goto(BASE + "/admin/article/new")
    page.click(".jodit-toolbar-button_campanellaImages button")
    page.wait_for_selector(".campanella-picker", timeout=5000)
    # A locator, not wait_for_function(): that evaluates a string, which the admin's
    # Content-Security-Policy (no 'unsafe-eval') refuses in some Chromium versions.
    page.locator(".campanella-picker__item, .campanella-picker__status:not(:empty)").first.wait_for(timeout=5000)
    check(page.locator(".campanella-picker__item").count() > 0 or "Nincs" in page.locator(".campanella-picker__status").inner_text(),
          "the image picker: neither images nor 'none': %s" % page.locator(".campanella-picker__status").inner_text())

    # The form refuses a save without a CSRF token.
    page.goto(BASE + "/admin/article/new")
    page.fill("#field-title", "CSRF nélkül")
    page.eval_on_selector("form.admin-form input[name=_csrf]", "e => e.remove()")
    page.click("form.admin-form button[type=submit]:not([name])")
    page.wait_for_load_state("load")
    page.goto(BASE + "/admin/article")
    check(page.get_by_text("CSRF nélkül").count() == 0, "an article was saved without a CSRF token")

    # The profile: a change of name saved.
    page.goto(BASE + "/admin/profile")
    page.fill("#profile-name", "Böngésző Adminisztrátor")
    page.click("form[action$='/admin/profile'] button[type=submit]")
    page.wait_for_load_state("load")
    check(page.locator(".alert-success").count() == 1, "saving the profile: no message")
    check(page.input_value("#profile-name") == "Böngésző Adminisztrátor", "saving the profile: the name did not change")

    # The System page: the version and the checks.
    page.goto(BASE + "/admin/system")
    version = re.search(r"CAMPANELLA\s*=\s*'([^']+)'", open(os.path.join(os.path.dirname(__file__), "..", "..", "src", "Core", "Version.php"), encoding="utf-8").read())
    check(version is not None and version.group(1) in page.locator("main").inner_text(), "the System page does not show the version")
    context.close()

    # An editor: the administrators' pages are forbidden, the others open.
    context = new_context(browser)
    page = context.new_page()
    login(page, EDITOR)
    for path in ADMIN_PAGES:
        status = status_of(page, path)
        expected = 403 if path in ADMINISTRATORS_ONLY else 200
        check(status == expected, "%s as an editor: %s, expected %s" % (path, status, expected))
    context.close()


def test_search(browser):
    """The header's search field leads to the results, the words marked; a draft is never found (since 0.2.7)."""
    context = new_context(browser)
    page = context.new_page()
    page.goto(BASE + "/")
    page.fill("#site-search-q", "capability")
    with page.expect_navigation():
        page.press("#site-search-q", "Enter")
    check(path_of(page) == "/search", "the search field: ended on %s" % page.url)
    check(page.locator(".search-result").count() > 0, "the search found nothing for 'capability'")
    check(page.locator(".search-result mark").count() > 0, "the words found are not marked")
    check(page.locator("meta[name=robots][content=noindex]").count() == 1, "the search page has no noindex")
    page.goto(BASE + "/search?q=piszkozat")
    check(page.locator(".search-result").count() == 0, "the search found a draft")
    context.close()


TESTS = [
    test_security_headers,
    test_login_and_logout,
    test_lockout,
    test_sessions,
    test_password_reset,
    test_contact_form,
    test_admin_pages,
    test_search,
]


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=not HEADED)
        for test in TESTS:
            name = test.__name__[5:].replace("_", " ")
            print("· " + name)
            failed = len(failures)
            try:
                test(browser)
            except Exception:   # noqa: BLE001 - one broken test must not hide the others
                failures.append("%s: %s" % (name, traceback.format_exc().strip().splitlines()[-1]))
                print(traceback.format_exc())
            if len(failures) == failed:
                print("    ✔")
        browser.close()

    for problem in dict.fromkeys(p.strip() for p in problems_seen):   # each once
        check(False, problem)
    print("%d checks, %d tests." % (checks, len(TESTS)))
    if failures:
        print("%d failures:" % len(failures))
        for failure in failures:
            print("  ✘ " + failure)
        sys.exit(1)
    print("✔ Every browser test passed.")


if __name__ == "__main__":
    main()

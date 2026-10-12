"""Installing the release package from the browser (since 0.2.0), as a site owner
without a command line does: run by tests/package/check.sh against the unpacked
package, served by PHP's web server, with an empty database.

Environment: CAMPANELLA_TEST_URL (the site), CAMPANELLA_TEST_INSTALL_KEY (the key in
config/local.php), CAMPANELLA_TEST_STEP: `nokey` (no key set yet: the page says how to
set one, and offers no form) or `install` (the key is set: the whole installation).
Exits with 1 if anything failed, listing every failure.
"""

import os
import sys
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

BASE = os.environ["CAMPANELLA_TEST_URL"].rstrip("/")
KEY = os.environ.get("CAMPANELLA_TEST_INSTALL_KEY", "")
STEP = os.environ.get("CAMPANELLA_TEST_STEP", "install")

failures = []
checks = 0
problems = []


def check(ok, what):
    global checks
    checks += 1
    if not ok:
        failures.append(what)


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        page = browser.new_page(locale="hu-HU")
        page.on("pageerror", lambda error: problems.append("JavaScript error on %s: %s" % (page.url, error)))
        page.on("console", lambda m: m.type == "error" and "Failed to load resource" not in m.text
                and problems.append("console error on %s: %s" % (page.url, m.text)))
        page.on("response", lambda r: r.status >= 400 and r.request.resource_type != "document"
                and problems.append("%s %s" % (r.status, r.url)))

        front = page.goto(BASE + "/")
        check(front.status == 503, "the front page before installing: %s" % front.status)

        response = page.goto(BASE + "/install")
        check(response.status == 200, "/install: %s" % response.status)
        check(response.headers.get("cache-control", "").find("no-store") >= 0, "/install may be cached")
        check("unsafe-inline" not in response.headers.get("content-security-policy", "script-src 'self'").split("script-src")[1].split(";")[0],
              "/install allows inline scripts")

        if STEP == "nokey":
            check(page.locator("form").count() == 0, "a form without an install key")
            check(page.locator(".alert-warning code").count() == 1, "no hint how to set the install key")
            browser.close()
            return

        # The requirements: none failed on a clean host.
        check(page.locator("form #install-key").count() == 1, "no installation form")
        check(page.locator(".text-bg-danger, .table-danger").count() == 0, "a requirement is not met: " + page.locator("main").inner_text()[:500])

        # A wrong key: refused, nothing installed.
        page.fill("#install-key", "rossz-kulcs-rossz-kulcs-rossz")
        page.fill("#install-name", "Csomag Admin")
        page.fill("#install-email", "csomag-admin@example.test")
        page.fill("#install-password", "csomag-admin-jelszo-1")
        page.fill("#install-password_again", "csomag-admin-jelszo-1")
        page.click("form button[type=submit]")
        page.wait_for_load_state("load")
        check(urlsplit(page.url).path == "/install" and page.locator(".alert-danger").count() == 1, "a wrong install key was accepted")

        # The right key: installed, logged in, in the admin.
        page.fill("#install-key", KEY)
        page.fill("#install-password", "csomag-admin-jelszo-1")
        page.fill("#install-password_again", "csomag-admin-jelszo-1")
        page.click("form button[type=submit]")
        page.wait_for_load_state("load")
        check(urlsplit(page.url).path == "/admin" and page.locator(".alert-success").count() == 1, "after installing: %s" % page.url)

        # The System page: no error on a fresh installation.
        page.goto(BASE + "/admin/system")
        errors = page.locator(".badge.text-bg-danger")
        check(errors.count() == 0, "the System page shows %d errors: %s" % (errors.count(), "; ".join(
            errors.nth(i).locator("xpath=ancestor::tr").inner_text().replace("\n", " ") for i in range(errors.count()))))

        # The site works, and the installer is gone.
        check(page.goto(BASE + "/").status == 200, "the front page after installing")
        check(page.goto(BASE + "/install").status == 404, "/install still open after installing")
        page.goto(BASE + "/admin")
        page.click("form[action$='/logout'] button[type=submit]")
        page.wait_for_load_state("load")
        response = page.goto(BASE + "/admin")
        check(urlsplit(page.url).path == "/login", "still logged in after logging out")

        # Logging in again with the password chosen at installing.
        page.fill("#email", "csomag-admin@example.test")
        page.fill("#password", "csomag-admin-jelszo-1")
        page.click("form[action$='/login'] button[type=submit]")
        page.wait_for_load_state("load")
        check(page.goto(BASE + "/admin").status == 200 and urlsplit(page.url).path == "/admin", "logging in with the installer's password")
        browser.close()


main()
for problem in dict.fromkeys(problems):
    check(False, problem)
print("%d checks of installing from the browser (%s)." % (checks, STEP))
if failures:
    print("%d failures:" % len(failures))
    for failure in failures:
        print("  ✘ " + failure)
    sys.exit(1)
print("✔ Installing from the browser works.")

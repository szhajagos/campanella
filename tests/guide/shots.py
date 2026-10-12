"""The site owners' guide's screenshots (since 0.2.1), run by tests/guide/screenshots.sh.

Goes through a fresh site as the guide does (installing it from the browser first),
and saves a PNG of each page the guide shows, named as the guide refers to it.
Environment: CAMPANELLA_GUIDE_URL, _LANG (hu or en), _SITE (the site's folder, for
config/local.php), _KEY (the install key to set), _MAILS (the mail catcher's folder),
_OUT (where the images go).
"""

import os
import sys
import time

from playwright.sync_api import sync_playwright

BASE = os.environ["CAMPANELLA_GUIDE_URL"].rstrip("/")
LANG = os.environ["CAMPANELLA_GUIDE_LANG"]
SITE = os.environ["CAMPANELLA_GUIDE_SITE"]
KEY = os.environ["CAMPANELLA_GUIDE_KEY"]
OUT = os.environ["CAMPANELLA_GUIDE_OUT"]

# The example administrator of the guide, in its language.
ADMIN = {
    "hu": {"name": "Kovács Anna", "email": "anna@pelda.hu", "password": "Hosszu-jelszo-2026"},
    "en": {"name": "Anna Smith", "email": "anna@example.com", "password": "Long-password-2026"},
}[LANG]

saved = []


def shot(page, name, full_page=False, element=None):
    """Saves the page (or one element of it) as <name>.png."""
    path = os.path.join(OUT, name + ".png")
    page.mouse.move(0, 0)   # no hover effect on the last link clicked
    if element is not None:
        page.locator(element).first.screenshot(path=path)
    else:
        page.screenshot(path=path, full_page=full_page)
    saved.append(name)


def set_local_config(text):
    with open(os.path.join(SITE, "config", "local.php"), "w", encoding="utf-8") as file:
        file.write(text)
    # PHP's opcache looks at a changed file again after opcache.revalidate_freq (2 s).
    time.sleep(2.5)


def installing(page):
    # Without an install key: the page says how to set one.
    page.goto(BASE + "/install")
    shot(page, "install-no-key")

    # With the key in config/local.php: the requirements and the form.
    set_local_config("<?php\nreturn [\n    'debug' => false,\n    'install' => ['key' => '%s'],\n];\n" % KEY)
    page.goto(BASE + "/install")
    page.fill("#install-key", KEY)
    page.fill("#install-name", ADMIN["name"])
    page.fill("#install-email", ADMIN["email"])
    page.fill("#install-password", ADMIN["password"])
    page.fill("#install-password_again", ADMIN["password"])
    shot(page, "install-form", full_page=True)
    page.click("form button[type=submit]")
    page.wait_for_load_state("load")
    if "/admin" not in page.url:
        sys.exit("Installing failed: " + page.url)
    shot(page, "admin-dashboard")
    # The key is removed, as the guide says.
    set_local_config("<?php\nreturn [\n    'debug' => false,\n];\n")


def first_steps(page):
    page.goto(BASE + "/")
    shot(page, "site-front")
    page.goto(BASE + "/admin/system/settings")
    shot(page, "settings", full_page=True)
    page.goto(BASE + "/admin/system")
    shot(page, "system")   # the top only: the rest shows the server's own folders
    # Logging out and in again.
    page.goto(BASE + "/admin")
    page.click("form[action$='/logout'] button[type=submit]")
    page.wait_for_load_state("load")
    page.goto(BASE + "/login")
    page.fill("#email", ADMIN["email"])
    shot(page, "login")
    page.fill("#password", ADMIN["password"])
    page.click("form[action$='/login'] button[type=submit]")
    page.wait_for_load_state("load")


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        context = browser.new_context(
            locale="hu-HU" if LANG == "hu" else "en-GB",
            viewport={"width": 1280, "height": 800},
            color_scheme="light",
        )
        page = context.new_page()
        installing(page)
        first_steps(page)
        browser.close()
    print("%d screenshots (%s): %s" % (len(saved), LANG, ", ".join(saved)))


main()

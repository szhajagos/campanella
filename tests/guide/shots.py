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

# The guide's example texts, in its language.
TEXT = {
    "hu": {
        "file": "balatoni-naplemente.jpg",
        "title": "Nyári nyitvatartás",
        "lead": "Júliusban és augusztusban rövidebb ideig vagyunk nyitva.",
        "body": "Július 1-jétől augusztus 31-ig hétköznap 9 és 15 óra között várunk.",
        "body2": "Szombaton és vasárnap zárva tartunk.",
        "image": "Balatoni naplemente",
        "alt": "Naplemente a Balaton fölött, vitorlásokkal",
        "menu_item": "Nyitvatartás",
    },
    "en": {
        "file": "sunset-over-the-lake.jpg",
        "title": "Summer opening hours",
        "lead": "In July and August we are open for shorter hours.",
        "body": "From 1 July to 31 August we are open on weekdays from 9 am to 3 pm.",
        "body2": "We are closed on Saturdays and Sundays.",
        "image": "Sunset over the lake",
        "alt": "Sunset over a lake, with sailing boats",
        "menu_item": "Opening hours",
    },
}[LANG]

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


def card_of(page, heading_selector):
    """The card (Bootstrap .card) around a heading."""
    return page.locator(heading_selector).locator("xpath=ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' card ')][1]")


def example_image():
    """A JPEG to upload: a sunset, drawn with PHP's GD (so no photo is needed in the repository)."""
    folder = os.path.join(SITE, "var", "cache", "guide")
    os.makedirs(folder, exist_ok=True)
    path = os.path.join(folder, TEXT["file"])
    import subprocess
    subprocess.run(["php", "-r", r"""
        $w = 1600; $h = 1000; $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h * 0.62; $y++) {
            $t = $y / ($h * 0.62);
            imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) (40 + 215 * $t), (int) (60 + 110 * $t), (int) (140 - 60 * $t)));
        }
        imagefilledellipse($im, (int) ($w * 0.62), (int) ($h * 0.6), 260, 260, imagecolorallocate($im, 255, 205, 110));
        for ($y = (int) ($h * 0.62); $y < $h; $y++) {
            $t = ($y - $h * 0.62) / ($h * 0.38);
            imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) (200 - 160 * $t), (int) (120 - 80 * $t), (int) (90 - 30 * $t)));
        }
        foreach ([[300, 640], [520, 660], [1150, 650]] as [$x, $y]) {
            imagefilledpolygon($im, [$x, $y, $x + 40, $y, $x + 20, $y - 90], imagecolorallocate($im, 245, 240, 230));
            imagefilledrectangle($im, $x - 10, $y, $x + 50, $y + 12, imagecolorallocate($im, 30, 25, 30));
        }
        imagejpeg($im, $argv[1], 88);
    """, path], check=True)
    return path


def content(page):
    # The list of articles.
    page.goto(BASE + "/admin/article")
    shot(page, "content-list")

    # A new article: the form, with the editor.
    page.goto(BASE + "/admin/article/new")
    page.fill("#field-title", TEXT["title"])
    page.fill("#field-lead", TEXT["lead"])
    editor = page.locator(".jodit-wysiwyg").first
    editor.click()
    page.keyboard.type(TEXT["body"])
    page.keyboard.press("Enter")
    page.keyboard.type(TEXT["body2"])
    page.locator(".admin-checkboxes input[type=checkbox]").first.check()
    page.locator("#field-title").click()
    shot(page, "article-form", full_page=True)
    shot(page, "editor", element=".jodit-container")

    # Saved as a draft: the publication card.
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")
    article_url = page.url
    shot(page, "publication-draft", element="#publication-heading >> xpath=ancestor::*[contains(@class, 'card')][1]")

    # Published now.
    page.locator("form[action$='/publish'] button[type=submit]").click()
    page.wait_for_load_state("load")

    # A scheduled one of the sample content.
    page.goto(BASE + "/admin/article?q=" + ("Id%C5%91z%C3%ADtett"))
    link = page.locator("table tbody a").first
    if link.count() > 0:
        link.click()
        page.wait_for_load_state("load")
        shot(page, "publication-scheduled", element="#publication-heading >> xpath=ancestor::*[contains(@class, 'card')][1]")

    # The categories (a tree), and deleting.
    page.goto(BASE + "/admin/category")
    shot(page, "category-list")
    page.goto(article_url + "/delete")
    shot(page, "delete")
    return article_url


def images(page):
    page.goto(BASE + "/admin/image")
    # Choosing the file uploads it at once (admin-upload.js), and reports it.
    page.set_input_files("#upload-file", example_image())
    page.wait_for_timeout(3000)
    page.goto(BASE + "/admin/image")
    shot(page, "image-list")
    page.locator("table tbody a").first.click()
    page.wait_for_load_state("load")
    if page.locator("#field-title").count():
        page.fill("#field-title", TEXT["image"])
    if page.locator("#field-alt").count():
        page.fill("#field-alt", TEXT["alt"])
    shot(page, "image-form", full_page=True)
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")


def image_in_text(page, article_url):
    """The editor's image button: uploading an image into the article's text."""
    page.goto(article_url)
    page.locator(".jodit-wysiwyg").first.click()
    page.keyboard.press("Control+End")
    page.keyboard.press("Enter")
    page.locator(".jodit-toolbar-button_image button").first.click()
    page.wait_for_timeout(500)
    shot(page, "editor-image", element=".jodit-container")
    page.locator(".jodit-popup input[type=file]").first.set_input_files(example_image())
    page.wait_for_timeout(3000)
    # The image's properties (double click): its alternative text.
    page.locator(".jodit-wysiwyg img").first.dblclick()
    page.wait_for_timeout(800)
    page.fill(".jodit-ui-image-main-tab__imageAlt", TEXT["alt"])
    shot(page, "editor-image-properties")
    page.locator(".jodit-dialog .jodit-ui-button_variant_primary").first.click()
    page.wait_for_timeout(500)
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")
    view = page.locator("a:has-text('" + ("Megtekintés" if LANG == "hu" else "View") + "')").first
    href = view.get_attribute("href")
    page.goto(BASE + href if href.startswith("/") else href)
    shot(page, "site-article", full_page=True)


def menus(page):
    page.goto(BASE + "/admin/menu_item")
    shot(page, "menu-items")
    page.goto(BASE + "/admin/menu_item/new")
    page.fill("#field-title", TEXT["menu_item"])
    page.locator("#field-menu").select_option(index=1)
    page.fill("#field-weight", "25")
    target = page.locator("#field-target option", has_text=TEXT["title"]).first.get_attribute("value")
    page.locator("#field-target").select_option(target)
    shot(page, "menu-item-form", full_page=True)
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")
    page.goto(BASE + "/")
    shot(page, "site-menu")


def main():
    with sync_playwright() as playwright:
        # The browser's own language (date fields), as a visitor's would be.
        browser = playwright.chromium.launch(args=["--lang=" + ("hu" if LANG == "hu" else "en-GB")], env={
            **os.environ, "LANG": "hu_HU.UTF-8" if LANG == "hu" else "en_GB.UTF-8", "LANGUAGE": "hu" if LANG == "hu" else "en_GB"})
        context = browser.new_context(
            locale="hu-HU" if LANG == "hu" else "en-GB",
            viewport={"width": 1280, "height": 800},
            color_scheme="light",
        )
        page = context.new_page()
        installing(page)
        first_steps(page)
        article_url = content(page)
        images(page)
        image_in_text(page, article_url)
        menus(page)
        browser.close()
    print("%d screenshots (%s): %s" % (len(saved), LANG, ", ".join(saved)))


main()

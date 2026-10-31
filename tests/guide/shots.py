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
        "editor_name": "Szabó Péter",
        "editor_email": "peter@pelda.hu",
        "editor_password": "Peter-jelszava-2026",
        "privacy_title": "Adatkezelési tájékoztató",
        "privacy_path": "/adatkezeles",
        "privacy_body": "Ez a tájékoztató leírja, milyen személyes adatokat kezelünk, és miért.",
        "visitor_name": "Nagy Eszter",
        "visitor_email": "eszter@pelda.hu",
        "visitor_subject": "Csoportos látogatás",
        "visitor_message": "Jó napot!\nHúszfős csoporttal jönnénk szeptemberben. Van lehetőség tárlatvezetésre?\n\nKöszönettel:\nEszter",
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
        "editor_name": "Peter Brown",
        "editor_email": "peter@example.com",
        "editor_password": "Peters-password-2026",
        "privacy_title": "Privacy notice",
        "privacy_path": "/privacy",
        "privacy_body": "This notice describes what personal data we handle, and why.",
        "visitor_name": "Emma Jones",
        "visitor_email": "emma@example.com",
        "visitor_subject": "A group visit",
        "visitor_message": "Hello,\nWe would come with a group of twenty in September. Is a guided tour possible?\n\nBest regards,\nEmma",
    },
}[LANG]

# The own content type of chapter 11 (an events calendar), in the guide's language: the
# Blueprint, its fields (label, name, type, required, queryable, extra), the admin's
# columns, the events (title, days from today, time, kind, place, price, booking), the
# list, a filter to show, a word to search, and an article's new path (a redirect).
OWN = {
    "hu": {
        "label": "Program", "name": "program",
        "fields": [
            ("Időpont", "idopont", "datetime", True, True, {}),
            ("Fajta", "fajta", "choice", True, True, {"choices": "eloadas | Előadás\nkoncert | Koncert\nkirandulas | Kirándulás"}),
            ("Helyszín", "helyszin", "string", False, False, {"length": "120"}),
            ("Belépő (Ft)", "belepo", "decimal", False, True, {"scale": "0"}),
            ("Jelentkezés szükséges", "jelentkezes", "boolean", False, True, {}),
        ],
        "columns": ["idopont", "fajta", "belepo", "jelentkezes"],
        "items": [
            ("Csillagászati est", 5, "19:00", "eloadas", "Csillagvizsgáló", "0", False, "Távcsöves bemutató derült idő esetén, előtte rövid előadás a téli égboltról."),
            ("Őszi kirándulás a Pilisbe", 12, "08:30", "kirandulas", "Indulás a Batthyány térről", "2000", True, "Egész napos túra, kb. 14 km. Hozz magaddal ennivalót és vizet."),
            ("Kórushangverseny", 20, "18:00", "koncert", "Zeneiskola, nagyterem", "1500", False, "A városi kórus őszi hangversenye magyar és európai szerzők műveiből."),
        ],
        "list_title": "Programnaptár", "list_name": "programnaptar", "list_path": "/programok",
        "filter": "kirandulas", "search": "kirándulás", "new_path": "/nyitvatartas",
        "extra_field": {"name": "korhatar", "type": "integer", "label": "Korhatár"},
    },
    "en": {
        "label": "Activity", "name": "activity",
        "fields": [
            ("Starts", "starts", "datetime", True, True, {}),
            ("Kind", "kind", "choice", True, True, {"choices": "talk | Talk\nconcert | Concert\nwalk | Walk"}),
            ("Venue", "venue", "string", False, False, {"length": "120"}),
            ("Ticket price", "price", "decimal", False, True, {"scale": "2"}),
            ("Booking required", "booking", "boolean", False, True, {}),
        ],
        "columns": ["starts", "kind", "price", "booking"],
        "items": [
            ("An evening of astronomy", 5, "19:00", "talk", "The observatory", "0", False, "Looking through telescopes if the sky is clear, after a short talk on the winter sky."),
            ("An autumn walk in the hills", 12, "08:30", "walk", "Meeting at the station", "5", True, "A day's walk of about 9 miles. Bring food and water."),
            ("Choir concert", 20, "18:00", "concert", "The music school's hall", "8.50", False, "The town choir's autumn concert, with music from across Europe."),
        ],
        "list_title": "Activity calendar", "list_name": "calendar", "list_path": "/activities",
        "filter": "walk", "search": "walk", "new_path": "/opening-hours",
        "extra_field": {"name": "min_age", "type": "integer", "label": "Minimum age"},
    },
}[LANG]

# The importer of chapter 12: a spreadsheet of more events, into the Blueprint of chapter 11.
IMPORT = {
    "hu": {
        "label": "Programok a táblázatból", "name": "programok_csv", "key": "Azonosító", "title": "Cím",
        "mapping": [("Időpont", "idopont"), ("Fajta", "fajta"), ("Helyszín", "helyszin"), ("Belépő", "belepo"), ("Jelentkezés", "jelentkezes"), ("Leírás", "body")],
        "file": "programok.csv",
        "rows": [
            "Azonosító;Cím;Időpont;Fajta;Helyszín;Belépő;Jelentkezés;Leírás",
            "P-101;Téli csillagnézés;{d1} 18:30;Előadás;Csillagvizsgáló;0;nem;Távcsöves bemutató a téli égboltról.",
            "P-102;Adventi koncert;{d2} 17:00;Koncert;Református templom;2 000;nem;Adventi dalok a városi kórussal.",
            "P-103;Kirándulás a Dobogókőre;{d3} 08:00;Kirándulás;Indulás a buszállomásról;3 500;igen;Egész napos túra, kb. 16 km.",
            "P-104;Újévi séta;jövő héten;Kirándulás;Fő tér;0;nem;",
        ],
    },
    "en": {
        "label": "Activities from the spreadsheet", "name": "activities_csv", "key": "ID", "title": "Title",
        "mapping": [("Starts", "starts"), ("Kind", "kind"), ("Venue", "venue"), ("Price", "price"), ("Booking", "booking"), ("Description", "body")],
        "file": "activities.csv",
        "rows": [
            "ID;Title;Starts;Kind;Venue;Price;Booking;Description",
            "E-101;Winter stargazing;{d1} 18:30;Talk;The observatory;0;no;Looking at the winter sky through telescopes.",
            "E-102;Advent concert;{d2} 17:00;Concert;St Mary's church;6.50;no;Advent songs with the town choir.",
            "E-103;A walk to the beacon;{d3} 08:00;Walk;Meeting at the bus station;4;yes;A day's walk of about 10 miles.",
            "E-104;New Year walk;next week;Walk;The market square;0;no;",
        ],
    },
}[LANG]

# Chapter 5's files to download (since 0.3.2): one for everyone, one for the members.
FILES = {
    "hu": {
        "public": "Őszi programfüzet.pdf", "public_text": "Az őszi programok",
        "members": "Tagnévsor 2026.csv", "members_rows": ["Név;Belépés éve", "Kovács Anna;2019", "Szabó Péter;2023"],
        "link_text": "Letölthető programfüzet",
    },
    "en": {
        "public": "Autumn programme.pdf", "public_text": "The autumn programme",
        "members": "Members 2026.csv", "members_rows": ["Name;Joined", "Anna Smith;2019", "Peter Brown;2023"],
        "link_text": "Download the programme",
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
    page.goto(BASE + "/admin/article?q=" + ("Id%C5%91z%C3%ADtett" if LANG == "hu" else "Scheduled"))
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


def example_pdf(text):
    """A small, real PDF with one line of text (so no document is needed in the repository)."""
    stream = "BT /F1 24 Tf 72 720 Td (%s) Tj ET" % text.encode("ascii", "replace").decode()
    objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        "<< /Length %d >>\nstream\n%s\nendstream" % (len(stream), stream),
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    out = "%PDF-1.4\n"
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += "%d 0 obj\n%s\nendobj\n" % (number, body)
    xref = len(out)
    out += "xref\n0 %d\n0000000000 65535 f \n" % (len(objects) + 1) + "".join("%010d 00000 n \n" % o for o in offsets)
    out += "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objects) + 1, xref)
    return out.encode("latin-1")


def files(page, article_url):
    """Chapter 5, files to download (since 0.3.2): the Files list, who may download, the media library, the file picker."""
    # The site's own role, as its owner sets it in config/local.php.
    set_local_config("<?php\nreturn [\n    'debug' => false,\n    'users' => ['roles' => ['member']],\n];\n")
    # Given as buffers with their names: the browser here may not take non-ASCII file paths.
    public = {"name": FILES["public"], "mimeType": "application/pdf", "buffer": example_pdf(FILES["public_text"])}
    members = {"name": FILES["members"], "mimeType": "text/csv", "buffer": ("\r\n".join(FILES["members_rows"]) + "\r\n").encode("utf-8")}

    page.goto(BASE + "/admin/file")
    page.set_input_files("#upload-file", public)
    page.locator("table.admin-list tbody tr").first.wait_for(timeout=15000)
    page.goto(BASE + "/admin/file")
    page.check("#upload-visible-roles")
    page.check("#upload-role-member")
    shot(page, "file-upload", element="form[data-media-upload]")
    page.set_input_files("#upload-file", members)
    page.wait_for_timeout(2500)
    page.goto(BASE + "/admin/file")
    shot(page, "file-list")
    page.locator("table.admin-list tbody a", has_text=os.path.splitext(FILES["members"])[0]).first.click()
    page.wait_for_load_state("load")
    shot(page, "file-form", full_page=True)

    page.goto(BASE + "/admin/media")
    shot(page, "media-library")

    # The editor's file picker: the selected text becomes a link to the file.
    page.goto(article_url)
    page.locator(".jodit-wysiwyg").first.click()
    page.keyboard.press("Control+End")
    page.keyboard.press("Enter")
    page.keyboard.type(FILES["link_text"])
    page.keyboard.press("Shift+Home")
    page.locator(".jodit-toolbar-button_campanellaFiles button").first.click()
    page.locator(".campanella-picker__file").first.wait_for(timeout=10000)
    shot(page, "editor-file", element=".jodit-dialog__panel")
    page.locator(".campanella-picker__file", has_text=os.path.splitext(FILES["public"])[0]).first.click()
    page.wait_for_function("() => (document.querySelector('textarea[data-editor]') || {}).value.includes('/files/')", timeout=5000)
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")


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


# Chapter 13, components (since 0.3.3): a snippet in an info box, and the visitor's card.
COMPONENTS = {
    "hu": {
        "snippet_title": "Elérhetőség",
        "snippet_body": "Telefon: +36 1 234 5678, e-mail: info@pelda.hu. Hétköznap 9 és 17 óra között.",
        "box_title": "Elérhetőség az oldalsávban",
        "box_key": "elerhetoseg_doboz",
        "card_title": "Bejelentkezett felhasználó",
        "card_key": "felhasznaloi_kartya",
    },
    "en": {
        "snippet_title": "Contact us",
        "snippet_body": "Phone: +44 20 7946 0000, e-mail: info@example.com. On weekdays from 9 am to 5 pm.",
        "box_title": "Contact in the sidebar",
        "box_key": "contact_box",
        "card_title": "Logged-in user",
        "card_key": "user_card",
    },
}[LANG]


def components(page):
    """Chapter 13 (since 0.3.3): a snippet, a component in three steps, the list."""
    page.goto(BASE + "/admin/snippet/new")
    page.fill("#field-title", COMPONENTS["snippet_title"])
    page.locator(".jodit-wysiwyg").first.click()
    page.keyboard.type(COMPONENTS["snippet_body"])
    shot(page, "snippet-form", full_page=True)
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")

    page.goto(BASE + "/admin/component/new/source?blueprint=snippet")
    shot(page, "component-source", full_page=True)
    page.locator("main .list-group a", has_text=COMPONENTS["snippet_title"]).first.click()
    page.wait_for_load_state("load")
    shot(page, "component-display", full_page=True)
    page.locator(".admin-displays a[href*='display=info_box']").first.click()
    page.wait_for_load_state("load")
    page.fill("#field-title", COMPONENTS["box_title"])
    page.fill("#field-machine_name", COMPONENTS["box_key"])
    page.select_option("#setting-style", "info")
    shot(page, "component-form", full_page=True)
    page.locator("form.admin-form button[type=submit]").first.click()
    page.wait_for_load_state("load")

    page.goto(BASE + "/admin/component/new?source=system:visitor&display=user_card")
    page.fill("#field-title", COMPONENTS["card_title"])
    page.fill("#field-machine_name", COMPONENTS["card_key"])
    page.select_option("#field-visible_to", "users")
    page.locator("form.admin-form button[type=submit]").first.click()
    page.wait_for_load_state("load")
    shot(page, "component-list")


def color_schemes(page):
    """Chapter 16 (since 0.3.6): the colour schemes page; the visitor's switch, and the site in dark (the visitor's own mode, the site's settings unchanged)."""
    page.goto(BASE + "/admin/system/color-schemes")
    shot(page, "color-schemes", full_page=True)
    view = page.context.browser.new_context(viewport={"width": 1280, "height": 800}, locale=page.context.pages[0].evaluate("navigator.language"), color_scheme="light")
    visitor = view.new_page()
    visitor.goto(BASE + "/")
    shot(visitor, "color-mode-switch", element=".site-header")
    with visitor.expect_response(lambda r: r.url.endswith("/color-mode")):
        visitor.locator(".display-color-mode-switch button[value=dark]").click()
    visitor.goto(BASE + "/")
    shot(visitor, "site-dark", full_page=True)
    view.close()


def layouts(page, article_url):
    """Chapter 14 (since 0.3.4): the layouts, a region's components, the contact box beside an article."""
    page.goto(BASE + "/admin/system/layouts")
    page.select_option("#layouts-bp-article", "sidebar")
    shot(page, "layouts", full_page=True)
    page.locator("form.admin-form button[type=submit]").first.click()
    page.wait_for_load_state("load")

    page.goto(BASE + "/admin/component/regions?layout=sidebar")
    region = page.locator("#region-sidebar")
    region.locator("select[name=component]").select_option(label=COMPONENTS["box_title"])
    region.locator("button[type=submit]").click()
    page.wait_for_load_state("load")
    shot(page, "regions", full_page=True)

    page.goto(BASE + "/admin/component")
    page.locator("table.admin-components a", has_text=COMPONENTS["box_title"]).first.click()
    page.wait_for_load_state("load")
    page.locator("#place-bp-article").check()
    shot(page, "component-place", element=".admin-component-place")
    page.locator("form.admin-form button[type=submit]").first.click()
    page.wait_for_load_state("load")

    page.goto(article_url)
    view = page.locator("a:has-text('" + ("Megtekintés" if LANG == "hu" else "View") + "')").first
    href = view.get_attribute("href")
    page.goto(BASE + href if href.startswith("/") else href)
    shot(page, "site-sidebar", full_page=True)


# Chapter 15, pages assembled from components (since 0.3.5): the sample front page.
PAGES = {
    "hu": {"new": "Új komponens", "title": "Belépés a címlapon", "key": "cimlap_belepes"},
    "en": {"new": "New component", "title": "Log in on the front page", "key": "front_login"},
}[LANG]


def pages(page):
    """Chapter 15 (since 0.3.5): a page's components, a new one for the page, the front page setting."""
    page.goto(BASE + "/admin/page")
    page.locator("main table a", has_text=("Üdvözlünk" if LANG == "hu" else "Welcome")).first.click()
    page.wait_for_load_state("load")
    shot(page, "page-form-components", element=".admin-page-components")
    page.click(".admin-page-components a")
    page.wait_for_load_state("load")
    shot(page, "page-components", full_page=True)

    page.locator("#region-sidebar a", has_text=PAGES["new"]).click()
    page.wait_for_load_state("load")
    page.click("main a[href*='source=system%3Avisitor']")
    page.wait_for_load_state("load")
    page.locator(".admin-displays a[href*='display=login_form']").first.click()
    page.wait_for_load_state("load")
    page.fill("#field-title", PAGES["title"])
    page.fill("#field-machine_name", PAGES["key"])
    page.select_option("#field-visible_to", "public")
    shot(page, "page-component-form", full_page=True)
    page.locator("form.admin-form button[type=submit]").first.click()
    page.wait_for_load_state("load")

    page.goto(BASE + "/admin/system/settings")
    shot(page, "settings-front-page", element="#settings-front_page >> xpath=ancestor::section[1]")
    # The front page, as a visitor sees it.
    view = page.context.browser.new_context(viewport={"width": 1280, "height": 800}, locale=page.context.pages[0].evaluate("navigator.language"), color_scheme="light")
    visitor = view.new_page()
    visitor.goto(BASE + "/")
    shot(visitor, "site-front-page", full_page=True)
    view.close()


def own_types(page, browser, article_url):
    """Chapter 11: an own content type (an events calendar) made in the admin, step by step."""
    import datetime
    import json
    submit = "form.admin-form button[type=submit], main form button[type=submit]"

    # The Blueprint: its name and capabilities.
    page.goto(BASE + "/admin/system/blueprints/new")
    page.fill("#bp-label", OWN["label"])
    page.fill("#bp-name", OWN["name"])
    for capability in ("textual", "routable", "publishable"):
        page.check("#bp-cap-" + capability)
    shot(page, "blueprint-new", full_page=True)
    page.locator("form[action$='/blueprints/new'] button[type=submit]").click()
    page.wait_for_load_state("load")
    blueprint_url = BASE + "/admin/system/blueprints/" + OWN["name"]

    # Its fields, one by one (the choice field shown filled in).
    for label, name, kind, required, queryable, extra in OWN["fields"]:
        page.goto(blueprint_url + "/fields/new")
        page.fill("#bp-label", label)
        page.fill("#bp-name", name)
        page.select_option("#bp-type", kind)
        if "choices" in extra:
            page.fill("#bp-choices", extra["choices"].replace("\\n", "\n"))
        if "length" in extra:
            page.fill("#bp-length", extra["length"])
        if "scale" in extra:
            page.fill("#bp-scale", extra["scale"])
        if required:
            page.check("#bp-required")
        if queryable:
            page.check("#bp-queryable")
        if kind == "choice":
            shot(page, "blueprint-field", full_page=True)
        page.locator("form[action$='/fields/new'] button[type=submit]").click()
        page.wait_for_load_state("load")

    # The Blueprint's page, and the admin's columns.
    page.goto(blueprint_url)
    for i, column in enumerate(OWN["columns"]):
        page.select_option("#bp-column-%d" % i, column)
    shot(page, "blueprint-edit")
    shot(page, "blueprint-columns", element="#columns")
    page.locator("form[action$='/columns'] button[type=submit]").click()
    page.wait_for_load_state("load")

    # The events, as an editor writes them (the first one shown).
    today = datetime.date.today()
    for index, (title, days, time_of_day, kind, place, price, booking, text) in enumerate(OWN["items"]):
        page.goto(BASE + "/admin/" + OWN["name"] + "/new")
        names = [f[1] for f in OWN["fields"]]
        page.fill("#field-title", title)
        page.fill("#field-" + names[0], (today + datetime.timedelta(days=days)).isoformat() + "T" + time_of_day)
        page.select_option("#field-" + names[1], kind)
        page.fill("#field-" + names[2], place)
        page.fill("#field-" + names[3], price)
        if booking:
            page.check("#field-" + names[4])
        page.locator(".jodit-wysiwyg").first.click()
        page.keyboard.type(text)
        page.locator("#field-title").click()
        if index == 0:
            shot(page, "own-form", full_page=True)
        page.locator("form.admin-form button[name=_publish]").click()
        page.wait_for_load_state("load")
    page.goto(BASE + "/admin/" + OWN["name"])
    shot(page, "own-list")

    # The list for the visitors: made, then its conditions, order, columns and filters.
    page.goto(BASE + "/admin/system/lists/new")
    page.fill("#list-title", OWN["list_title"])
    page.fill("#list-name", OWN["list_name"])
    page.select_option("#list-blueprint", OWN["name"])
    shot(page, "list-new")
    page.locator("form[action$='/lists/new'] button[type=submit]").click()
    page.wait_for_load_state("load")
    starts = OWN["fields"][0][1]
    page.fill("#list-path", OWN["list_path"])
    page.select_option("#list-display", "table")
    page.select_option("select[name='conditions[0][field]']", starts)
    page.select_option("select[name='conditions[0][op]']", ">=")
    page.select_option("select[name='conditions[0][relative]']", "now")
    page.select_option("select[name='sort[0][field]']", starts)
    page.select_option("select[name='sort[0][dir]']", "asc")
    for name in [f[1] for f in OWN["fields"]][:4]:
        page.check("#list-col-" + name)
    for name in OWN["columns"]:
        page.check("#list-filter-" + name)
    shot(page, "list-edit", full_page=True)
    page.locator("form[action$='/lists/" + OWN["list_name"] + "'] button[type=submit]").click()
    page.wait_for_load_state("load")

    # The list's page, as a visitor sees it; then filtered.
    visitor = browser.new_context(viewport={"width": 1280, "height": 900}, locale="hu-HU" if LANG == "hu" else "en-GB")
    view = visitor.new_page()
    view.goto(BASE + OWN["list_path"])
    shot(view, "site-list", full_page=True)
    view.select_option("#list-filter-" + OWN["fields"][1][1], OWN["filter"])
    view.locator("form.list-filters button[type=submit]").click()
    view.wait_for_load_state("load")
    shot(view, "site-list-filtered", full_page=True)

    # The search (chapter 3).
    view.goto(BASE + "/")
    view.fill("header input[name=q]", OWN["search"])
    view.press("header input[name=q]", "Enter")
    view.wait_for_load_state("load")
    shot(view, "site-search", full_page=True)
    visitor.close()

    # Carrying the content model to another site: the export, and an import's plan
    # (the same file with one field more, as if changed on a test site; not applied).
    page.goto(BASE + "/admin/system/model")
    shot(page, "model")
    model = json.loads(page.request.get(BASE + "/admin/system/model/export").text())
    extra = OWN["extra_field"]
    model["blueprints"][OWN["name"]]["fields"].append({"name": extra["name"], "type": extra["type"], "label": extra["label"]})
    path = os.path.join(SITE, "var", "cache", "guide", "campanella-model.json")
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as file:
        json.dump(model, file, ensure_ascii=False, indent=2)
    page.set_input_files("#model-file", path)
    page.locator("form[action$='/model/import'] button[type=submit]").click()
    page.wait_for_load_state("load")
    shot(page, "model-plan", full_page=True)

    # A changed path leads on (chapter 3): the article gets a new one.
    page.goto(article_url)
    page.fill("#field-path", OWN["new_path"])
    page.locator("form.admin-form button[type=submit]:not([name])").click()
    page.wait_for_load_state("load")
    page.goto(BASE + "/admin/system/redirects")
    shot(page, "redirects")


def importing(page):
    """Chapter 12: an importer of a CSV file into chapter 11's Blueprint: made, mapped, a trial, a run, its log."""
    import datetime
    # The list's CSV export (its button on the admin's list).
    page.goto(BASE + "/admin/" + OWN["name"])
    shot(page, "export-button", element="main .d-flex >> nth=0")

    page.goto(BASE + "/admin/system/importers/new")
    page.fill("#imp-label", IMPORT["label"])
    page.fill("#imp-name", IMPORT["name"])
    page.select_option("#imp-blueprint", OWN["name"])
    page.fill("#imp-key", IMPORT["key"])
    page.fill("#imp-title", IMPORT["title"])
    shot(page, "importer-new", full_page=True)
    page.locator("form.admin-form button[type=submit]").click()
    page.wait_for_load_state("load")
    for i, (source, target) in enumerate(IMPORT["mapping"], start=1):
        page.fill("input[name='mapping[%d][source]']" % i, source)
        page.select_option("select[name='mapping[%d][target]']" % i, target)
    page.check("#imp-publish")
    page.locator("form.admin-form button[type=submit]").click()
    page.wait_for_load_state("load")
    shot(page, "importer-edit", full_page=True)

    # A file: a trial, then the run.
    today = datetime.date.today()
    days = {"d1": today + datetime.timedelta(days=30), "d2": today + datetime.timedelta(days=36), "d3": today + datetime.timedelta(days=44)}
    lines = [row.format(**{k: (v.strftime("%Y. %m. %d.") if LANG == "hu" else v.isoformat()) for k, v in days.items()}) for row in IMPORT["rows"]]
    folder = os.path.join(SITE, "var", "cache", "guide")
    os.makedirs(folder, exist_ok=True)
    path = os.path.join(folder, IMPORT["file"])
    with open(path, "w", encoding="utf-8") as file:
        file.write("\r\n".join(lines) + "\r\n")
    page.goto(BASE + "/admin/system/importers/" + IMPORT["name"] + "/run")
    page.set_input_files("#imp-file", path)
    page.locator("form[enctype] button[type=submit]").click()
    page.wait_for_load_state("load")
    shot(page, "importer-trial", full_page=True)
    page.locator("form[action$='/start'] button[type=submit]").click()
    page.wait_for_load_state("load")
    page.locator("main .badge", has_text="kész" if LANG == "hu" else "done").first.wait_for(timeout=30000)
    shot(page, "importer-log", full_page=True)
    page.goto(BASE + "/admin/system/importers")
    shot(page, "importers")


MAILS = os.environ.get("CAMPANELLA_GUIDE_MAILS", "")


def last_mail_text():
    """The text of the newest caught e-mail (printed for the guide's author)."""
    import email
    import email.policy
    names = sorted(f for f in os.listdir(MAILS) if f.endswith(".eml"))
    if not names:
        return ""
    with open(os.path.join(MAILS, names[-1]), "rb") as file:
        message = email.message_from_binary_file(file, policy=email.policy.default)
    body = message.get_body(preferencelist=("plain",))
    return "Subject: %s\n%s" % (message["Subject"], body.get_content() if body else "")


def users(page, browser):
    # A new editor.
    page.goto(BASE + "/admin/user/new")
    page.fill("#user-name", TEXT["editor_name"])
    page.fill("#user-email", TEXT["editor_email"])
    page.fill("#user-password", TEXT["editor_password"])
    page.fill("#user-password_again", TEXT["editor_password"])
    page.check("#user-role-editor")
    shot(page, "user-form", full_page=True)
    page.locator("form.admin-form button[type=submit]").first.click()
    page.wait_for_load_state("load")
    page.goto(BASE + "/admin/user")
    shot(page, "user-list")
    page.locator("table tbody a", has_text=TEXT["editor_name"]).first.click()
    page.wait_for_load_state("load")
    shot(page, "user-edit", full_page=True)

    # The profile, with a second login (a phone).
    phone = browser.new_context(user_agent="Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36",
                                viewport={"width": 400, "height": 800})
    other = phone.new_page()
    other.goto(BASE + "/login")
    other.fill("#email", ADMIN["email"])
    other.fill("#password", ADMIN["password"])
    other.click("form[action$='/login'] button[type=submit]")
    other.wait_for_load_state("load")
    page.goto(BASE + "/admin/profile")
    shot(page, "profile", full_page=True)
    phone.close()

    # The forgotten password, as the editor (logged out).
    visitor = browser.new_context(viewport={"width": 1280, "height": 800})
    reset = visitor.new_page()
    reset.goto(BASE + "/password-reset")
    reset.fill("#email", TEXT["editor_email"])
    shot(reset, "reset-request")
    reset.click("main form button[type=submit]")
    reset.wait_for_load_state("load")
    shot(reset, "reset-sent")
    time.sleep(2)
    text = last_mail_text()
    print("--- RESET MAIL ---\n" + text)
    import re
    link = re.search(r"https?://\S+/password-reset\?token=[0-9a-f]+", text)
    if link:
        reset.goto(link.group(0))
        shot(reset, "reset-new")
    visitor.close()


def contact(page, browser):
    # The privacy notice: a page, and the site setting pointing to it.
    page.goto(BASE + "/admin/page/new")
    page.fill("#field-title", TEXT["privacy_title"])
    page.locator(".jodit-wysiwyg").first.click()
    page.keyboard.type(TEXT["privacy_body"])
    page.fill("#field-path", TEXT["privacy_path"])
    page.locator("form.admin-form button[name=_publish]").click()
    page.wait_for_load_state("load")
    page.goto(BASE + "/admin/system/settings")
    page.fill("input[name=privacy_url]", TEXT["privacy_path"])
    shot(page, "settings-privacy", element="input[name=privacy_url] >> xpath=ancestor::*[contains(@class, 'card')][1]")
    page.locator("form button[type=submit]").last.click()
    page.wait_for_load_state("load")

    # A visitor writes.
    visitor = browser.new_context(viewport={"width": 1280, "height": 900})
    form = visitor.new_page()
    form.goto(BASE + "/contact")
    form.fill("#contact-name", TEXT["visitor_name"])
    form.fill("#contact-email", TEXT["visitor_email"])
    form.fill("#contact-subject", TEXT["visitor_subject"])
    form.fill("#contact-message", TEXT["visitor_message"].replace("\\n", "\n"))
    time.sleep(3.5)
    shot(form, "contact-form", full_page=True)
    form.click("main form button[type=submit]")
    form.wait_for_load_state("load")
    shot(form, "contact-sent")
    visitor.close()
    time.sleep(2)
    print("--- CONTACT MAIL ---\n" + last_mail_text())

    # The administrator reads it.
    page.goto(BASE + "/admin/submission")
    shot(page, "submissions")
    page.locator("table tbody a").first.click()
    page.wait_for_load_state("load")
    shot(page, "submission", full_page=True)


def mail(page):
    # The test e-mail: from the E-mail page (or the System page); back on the System page.
    page.goto(BASE + "/admin/system/mail")
    page.locator("form[action$='/mail-test'] button[type=submit]").click()
    page.wait_for_load_state("load")
    shot(page, "mail-test-sent")
    time.sleep(1)
    page.goto(BASE + "/admin/system/mail")
    shot(page, "mail-log")
    print("--- TEST MAIL ---\n" + last_mail_text())


def set_schema_version(version, pending):
    """As if the files of a newer version had just been uploaded: the database's schema is older."""
    import subprocess
    subprocess.run(["php", "-r", r"""
        $pdo = new PDO("mysql:host=" . (getenv("CAMPANELLA_DB_HOST") ?: "localhost") . ";port=" . (getenv("CAMPANELLA_DB_PORT") ?: 3306) . ";dbname=" . (getenv("CAMPANELLA_DB_NAME") ?: "campanella"),
            getenv("CAMPANELLA_DB_USER") ?: "campanella", getenv("CAMPANELLA_DB_PASSWORD") ?: "");
        $prefix = getenv("CAMPANELLA_DB_PREFIX");
        $pdo->prepare("UPDATE {$prefix}system SET value = ? WHERE name = 'schema_version'")->execute([$argv[1]]);
        // The last migration pending again too (it can run again), so the page lists one.
        $pdo->prepare("DELETE FROM {$prefix}migrations WHERE id = ?")->execute([$argv[2]]);
    """, version, pending], check=True)


def upgrading(page, browser):
    set_schema_version("11", "core:0008_media_usage")
    visitor = browser.new_context(viewport={"width": 1280, "height": 800})
    view = visitor.new_page()
    view.goto(BASE + "/")
    shot(view, "upgrade-visitor")
    visitor.close()
    page.goto(BASE + "/admin/upgrade")
    # The site lives in a temporary folder here: show a host-like path instead.
    page.evaluate("""() => document.querySelectorAll('code').forEach(c => {
        c.textContent = c.textContent.replace(/^.*\\/site\\//, '/home/example/campanella/');
    })""")
    shot(page, "upgrade-page", full_page=True)
    page.locator("form button[type=submit]").last.click()
    page.wait_for_load_state("load")
    shot(page, "upgrade-done", full_page=True)


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
        files(page, article_url)
        menus(page)
        components(page)
        layouts(page, article_url)
        pages(page)
        color_schemes(page)
        own_types(page, browser, article_url)
        importing(page)
        users(page, browser)
        contact(page, browser)
        mail(page)
        upgrading(page, browser)
        browser.close()
    print("%d screenshots (%s): %s" % (len(saved), LANG, ", ".join(saved)))


main()

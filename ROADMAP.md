# Ütemterv

A Campanella kis lépésekben fejlődik: minden funkció saját verziót kap
(0.0.3, 0.0.4, …), saját tesztekkel, dokumentációval és CHANGELOG-bejegyzéssel.
Amikor a rendszer valódi, böngészőből kezelhető weboldalra alkalmas (a 0.0.6
végére), a verzió 0.1.0 lesz.

Az ütemterv irány, nem ígéret: a sorrend a tapasztalatok alapján változhat.
A már elkészült változásokat a [CHANGELOG](CHANGELOG.md) sorolja fel.

## Kész

| Verzió | Tartalom |
|---|---|
| 0.0.1 | Objektummodell, Capability-szerződés, Blueprint, Query, jogosultság-tudatos lekérdezés, Twig-megjelenítés, CLI |
| 0.0.2 | Kapcsolatok (Relationship): cikk → kategóriák, `whereRelated`, `RelationLoader`, Blueprint-listák |
| 0.0.3 | Felhasználók és bejelentkezés: `Identifiable`, `Authenticatable`, `Authorable`, munkamenet, CSRF, próbálkozás-korlátozás, `LoginGuard` + honeypot, `editor` szerepkör, `user:*` parancsok; MIT licenc |

## Következik

### Közbülső lépés: automatikus ellenőrzés (GitHub Actions)

- Minden pushnál: tesztek MariaDB-vel, PHPStan, `docs:check`, PHP 8.3-on.
- Verziócímkénél (`v*`): a `vendor` mappát is tartalmazó telepítőcsomag
  elkészítése és csatolása a GitHub-kiadáshoz.

**Kész, ha:** a GitHubon minden commit mellett látszik, hogy átment-e az
ellenőrzésen, és a `v0.0.3` címke után letölthető csomag jelenik meg.

### 0.0.4 – Admin felület

- Tartalmak listázása, szűrése, létrehozása, szerkesztése, törlése,
  publikálása böngészőből.
- Az űrlapok a mezők és kapcsolatok definícióiból készülnek, így egy új
  Blueprint vagy capability szerkesztőfelülete automatikusan létrejön.
- Érthető hibaüzenetek a `ValidationException` alapján.
- Megjelenés: Bootstrap 5.3, a Campanellával együtt szállítva
  (`public/assets/vendor/bootstrap`), CDN nélkül. Az admin felület és az
  alapértelmezett nyilvános téma is erre épül.
- Egyszerű témarendszer: a téma mappájában lévő sablon elsőbbséget kap az
  alapsablonnal szemben, így egy saját téma Bootstrap nélkül is készülhet.
- HTML-szűrő a `html` formátumú szövegekhez (a WYSIWYG-szerkesztő előfeltétele).
- WYSIWYG-szerkesztő a `html` formátumú szövegmezőkhöz: Jodit (MIT
  alapváltozat), helyben szállítva, cserélhető illesztéssel és mezőnként
  választható eszköztár-profillal. Képfeltöltés a Campanella saját
  végpontjára (a Jodit PHP-connectora nélkül).

**Kész, ha:** a példaoldal minden tartalma kezelhető böngészőből, parancssor nélkül.

### 0.0.5 – Migrációk

- Verziózott migrációs lépések (pl. új oszlop, új capability meglévő
  objektumokra, adatátalakítás), a `cc_system` táblában nyilvántartva.
- Az `install` a hiányzó migrációkat is lefuttatja.
- A migráció előtt figyelmeztetés az adatbázis mentésére.

**Kész, ha:** egy capability új mezője vagy egy Blueprinthez adott
capability kézi SQL nélkül átvezethető a meglévő tartalomra.

### 0.0.6 – Hierarchia és menü

- `Hierarchical` capability: `parent` kapcsolat, a fa gyors lekérdezése
  (materialized path), körkörös hivatkozás tiltása.
- `Weighted`/`Ordered`: kézi sorrend.
- Menü mint objektum, menüpontok mint objektumok; fa-megjelenítés.
- Taxonómia-fa (egymásba ágyazott kategóriák).

**Kész, ha:** a főmenü az adminból szerkeszthető, és a kategóriák fába rendezhetők.

### 0.1.0 – Első mérföldkő

Az 1–4. lépés együtt: bejelentkezés, admin, migrációk, menü. Innentől a
rendszer valódi weboldal kezelésére alkalmas.

## Később

- **Event / Action / Workflow:** események (`ObjectPublished` …), ezekre
  kötött műveletek (e-mail, webhook), állapotgép a publikáláshoz.
- **Component / Region / Layout / Page:** oldalak összeállítása
  komponensekből, a Drupal-féle blokkok helyett.
- **Webform:** űrlapok mint objektumműveletek felhasználói felülete.
- **Cache:** objektum-, lekérdezés- és render-cache cache tagekkel és
  kontextusokkal.
- **JSON API** a [HTTP API tervezet](docs/http-api/README.md) szerint.
- **Média:** fájltárolás, képek, képváltozatok.
- **Többnyelvűség.**
- **Keresés**, URL-aliasok és átirányítások, lomtár, audit-napló.
- **Belépés kiegészítései:** kétlépcsős azonosítás (pl. TOTP) saját
  capability-ként, a jelszó-ellenőrzés és a beléptetés közé illesztve;
  további `LoginGuard`-ok (CAPTCHA); elfelejtett jelszó e-mailben (az
  Event/Action és a levélküldés után); munkamenetek listája és kiléptetése.

## Elfogadott döntések

- **Licenc: MIT** (2026-09-29). Csak MIT-tel kompatibilis licencű függőség
  használható (MIT, BSD, Apache 2.0 stb.); GPL-es nem.
- **PHP 8.3** a minimum; MariaDB 10.6+ / MySQL 8.0+ közös részhalmaza.
- **Megjelenés: Bootstrap 5.3** az admin felülethez és az alapértelmezett
  témához, helyben szállítva, cserélhető témával (2026-09-29).
- **WYSIWYG: Jodit**, csak az ingyenes MIT változat; a PRO nem opció. Ha egy
  fontos funkció csak a PRO-ban érhető el, és nem pótolható saját
  bővítménnyel, a tartalék a SunEditor (2026-09-29). A beküldött HTML-t a
  szerkesztőtől függetlenül mindig a szerver szűri.
- **jQuery-függő komponens nem használható.**

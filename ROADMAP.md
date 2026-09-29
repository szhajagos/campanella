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

A 0.0.4 és a 0.0.5 eredetileg egy lépés volt; a HTML-szerkesztés biztonsági
okból külön kiadásba került (2026-09-29).

Első lépésként, még az űrlapok előtt:

- **Mezők értékeinek száma (számosság).** A `Field` új `cardinality`
  tulajdonsága: `1` (alapérték, a meglévő mezők nem változnak), egy felső
  korlát (pl. `3`), vagy `Field::UNLIMITED`. Többértékű mezőnél a `get()`
  listát ad; a típus minden elemre érvényes, a `required` legalább egy értéket
  jelent, a darabszám nem lépheti túl a korlátot.
- Tárolás: a nem lekérdezhető (`Data`) többértékű mező a data JSON-ban
  listaként él; a lekérdezhető (`Table`/`indexed`) egy új, közös
  `cc_field_values` táblában (`object_id`, `field`, `delta`, típusonkénti
  érték-oszlopok, indexekkel). Többértékű mező nem kaphat saját oszlopot.
- Query: a feltétel többértékű mezőn `EXISTS` al-lekérdezésre fordul
  („bármelyik értéke”); a Query-nyelv nem változik. Többértékű mező szerinti
  rendezés nem megengedett.
- A számosságot a capability határozza meg; a Blueprint szűkítheti (pl. 3 → 2),
  de egyértékűből többértékűt (vagy fordítva) nem csinálhat.
- Kapcsolatoknál felső korlát: `new Relation(..., Cardinality::Many, max: 3)`.
- A `StringList` a „többértékű String, `Data` tárolással” rövidítése lesz.
- Sémafrissítés: a `cc_field_values` tábla.

Utána:

- Tartalmak listázása, szűrése, létrehozása, szerkesztése, törlése,
  publikálása böngészőből.
- Az űrlapok a mezők és kapcsolatok definícióiból készülnek, így egy új
  Blueprint vagy capability szerkesztőfelülete automatikusan létrejön.
  Többértékű mezőnél „Még egy érték” gomb és sorrendezés, a számosság
  korlátjáig.
- Érthető hibaüzenetek a `ValidationException` alapján.
- Megjelenés: Bootstrap 5.3, a Campanellával együtt szállítva
  (`public/assets/vendor/bootstrap`), CDN nélkül. Az admin felület és az
  alapértelmezett nyilvános téma is erre épül.
- Egyszerű témarendszer: a téma mappájában lévő sablon elsőbbséget kap az
  alapsablonnal szemben, így egy saját téma Bootstrap nélkül is készülhet.
- A `html` formátumú szövegmezők ebben a lépésben még sima szövegdobozt
  kapnak; a szerkesztő és a szűrő a 0.0.5-ben jön.

**Kész, ha:** a példaoldal minden tartalma kezelhető böngészőből, parancssor nélkül.

### 0.0.5 – HTML-szerkesztés

- HTML-szűrő a `html` formátumú szövegekhez, szerveroldalon, engedélyezőlista
  alapján (jelölt: `symfony/html-sanitizer`, MIT; a HTMLPurifier LGPL, ezért
  nem). A szűrő mentéskor fut, a szerkesztőtől függetlenül.
- WYSIWYG-szerkesztő a `html` formátumú szövegmezőkhöz: Jodit (MIT
  alapváltozat), helyben szállítva, cserélhető illesztéssel és mezőnként
  választható eszköztár-profillal.
- Képfeltöltés a Campanella saját végpontjára (a Jodit PHP-connectora
  nélkül), a média-témakör legszükségesebb részeként: fájltárolás,
  típus- és méretellenőrzés.

**Kész, ha:** egy cikk törzse böngészőben formázható, képpel együtt, és a
beküldött HTML-ből a szűrő minden nem engedélyezett elemet eltávolít.

### 0.0.6 – Migrációk

- Verziózott migrációs lépések (pl. új oszlop, új capability meglévő
  objektumokra, adatátalakítás), a `cc_system` táblában nyilvántartva.
- Az `install` a hiányzó migrációkat is lefuttatja.
- A migráció előtt figyelmeztetés az adatbázis mentésére.

**Kész, ha:** egy capability új mezője vagy egy Blueprinthez adott
capability kézi SQL nélkül átvezethető a meglévő tartalomra.

### 0.0.7 – Hierarchia és menü

- `Hierarchical` capability: `parent` kapcsolat, a fa gyors lekérdezése
  (materialized path), körkörös hivatkozás tiltása.
- `Weighted`/`Ordered`: kézi sorrend.
- Menü mint objektum, menüpontok mint objektumok; fa-megjelenítés.
- Taxonómia-fa (egymásba ágyazott kategóriák).

**Kész, ha:** a főmenü az adminból szerkeszthető, és a kategóriák fába rendezhetők.

### 0.1.0 – Első mérföldkő

A 0.0.3–0.0.7 együtt: bejelentkezés, admin, HTML-szerkesztés, migrációk, menü. Innentől a
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
- **Többértékű mezők:** a mező számossága a `Field`-en van; lekérdezhető
  többértékű mező a közös `cc_field_values` táblában él, soha nem JSON-ban
  keresünk (2026-09-29). Ami hivatkozás más dologra (címke, kép, szerző), az
  objektum és kapcsolat, nem többértékű mező.

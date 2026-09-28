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

## Következik

### Közbülső lépés: automatikus ellenőrzés (GitHub Actions)

- Minden pushnál: tesztek MariaDB-vel, PHPStan, `docs:check`, PHP 8.3-on.
- Verziócímkénél (`v*`): a `vendor` mappát is tartalmazó telepítőcsomag
  elkészítése és csatolása a GitHub-kiadáshoz.

**Kész, ha:** a GitHubon minden commit mellett látszik, hogy átment-e az
ellenőrzésen, és a `v0.0.3` címke után letölthető csomag jelenik meg.

### 0.0.3 – Felhasználók és bejelentkezés

- `user` Blueprint: `Identifiable` (egyedi e-mail), `Authenticatable`
  (jelszó `password_hash`/`password_verify`, újrahash-elés szükség esetén).
- Belépés, kilépés; biztonságos munkamenet (HttpOnly, SameSite, Secure
  cookie; munkamenet-azonosító cseréje belépéskor).
- CSRF-védelem minden űrlaphoz.
- Belépési próbálkozások korlátozása.
- A bejelentkezett felhasználó `Actor`-ként, szerepköreivel érvényesül az
  `AccessPolicy`-ben.
- `Authorable` capability: `author` kapcsolat a felhasználóra.
- CLI: `user:create`, `user:password`.

**Kész, ha:** egy adminisztrátor be tud lépni, és belépve látja a
piszkozatokat is; kilépve nem.

### 0.0.4 – Admin felület

- Tartalmak listázása, szűrése, létrehozása, szerkesztése, törlése,
  publikálása böngészőből.
- Az űrlapok a mezők és kapcsolatok definícióiból készülnek, így egy új
  Blueprint vagy capability szerkesztőfelülete automatikusan létrejön.
- Érthető hibaüzenetek a `ValidationException` alapján.
- HTML-szűrő a `html` formátumú szövegekhez (a WYSIWYG-szerkesztő előfeltétele).

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
- **WYSIWYG-szerkesztő** (a licencdöntéstől függően CKEditor 5 vagy más).
- **Keresés**, URL-aliasok és átirányítások, lomtár, audit-napló.

## Nyitott döntések

- **A Campanella licence.** GPL-kompatibilis licenc esetén a CKEditor 5
  használható; megengedőbb (pl. MIT) licencnél más szerkesztő kell.
- **Alapértelmezett megjelenés:** saját CSS vagy Bootstrap 5, a nyilvános
  oldalon és az admin felületen.

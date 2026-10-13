# 8. E-mail

A Campanella e-mailt küld az elfelejtett jelszóhoz és a kapcsolati űrlap
üzeneteiről. Ehhez meg kell adnod, melyik levelezőszerveren keresztül
küldjön. Amíg ez nincs beállítva, semmilyen e-mail nem megy ki, és a Rendszer
oldal figyelmeztet.

## Mire lesz szükséged?

A tárhely levelezési adataira. A legtöbb tárhelyen a felületen létrehozhatsz
egy postafiókot (például `noreply@pelda.hu`), és megtalálod a kimenő
levelezőszerver (SMTP) adatait:

- a szerver címe (például `mail.pelda.hu`),
- a port (általában **587**, vagy **465**),
- a felhasználónév (általában a teljes e-mail-cím) és a jelszó.

**A feladó a webhely saját domainjén legyen** (például `noreply@pelda.hu`
egy `pelda.hu` oldalon), és olyan cím, amelyről a levelezőszerver küldhet.
Különben a levelek a címzettek levélszemét mappájában landolhatnak. A
tárhelyek a domain levelezési beállításait (SPF, DKIM) általában maguktól
elvégzik. Ha mégsem, a szolgáltató segít.

## Beállítás

A beállítás a `config/local.php` fájlba kerül, nem az adminba: a
levelezőszerver jelszavának nincs helye az adatbázisban. Írd be a tömbbe
(például az adatbázis beállításai után):

```php
'mail' => [
    'dsn' => 'smtp://noreply%40pelda.hu:a-postafiok-jelszava@mail.pelda.hu:587',
    'from' => 'noreply@pelda.hu',
    'from_name' => 'Pelda Kft.',
],
```

- A `dsn` egy sorban tartalmazza a szerver adatait:
  `smtp://felhasználó:jelszó@szerver:port`.
  - A 465-ös porthoz `smtps://` kell: `smtps://felhasználó:jelszó@szerver:465`.
  - Ha a felhasználónévben vagy a jelszóban különleges karakter van, azt
    kódolva kell írni: `@` helyett `%40`, `:` helyett `%3A`, `/` helyett
    `%2F`, `#` helyett `%23`. A példában a felhasználónév `noreply@pelda.hu`,
    ezért szerepel benne `%40`.
  - Ha a tárhely nem ad SMTP-szervert, a `'dsn' => 'native://default'` a PHP
    saját levélküldését használja (ez nem minden tárhelyen működik).
- A `from` a feladó címe.
- A `from_name` a feladó neve, ahogy a címzett látja. Ha elhagyod, a webhely
  neve lesz.

Mentsd el a fájlt. A beállítás azonnal érvényes.

## Kipróbálás

A **Rendszer → E-mail** oldalon (vagy a Rendszer oldal E-mail részében) a
**Teszt e-mail küldése magamnak** gomb egy próbalevelet küld a saját
e-mail-címedre:

![A teszt e-mail elküldve](../images/hu/mail-test-sent.png)

Ha néhány perc alatt sem érkezik meg, nézd meg a levélszemét mappát is. Ha
ott sincs, nézd meg a naplót (lent), és a Rendszer oldal **E-mail** részét:
ott látszik, ha a beállítás hibás.

## Az e-mail-napló

A **Rendszer → E-mail** oldal mutatja, milyen leveleket küldött az oldal:

![Az e-mail-napló](../images/hu/mail-log.png)

- Az időpont, a címzett, a tárgy és a levél fajtája (zárójelben: `test`,
  `password_reset`, `submission` …).
- Az **Eredmény**: **elküldve**, vagy **sikertelen** a levelezőszerver
  hibaüzenetével, vagy **nincs beállítva**, ha az e-mail-küldés nem volt
  beállítva.
- A napló 90 napig őrzi a bejegyzéseket, a levelek szövegét soha.

Az „elküldve” azt jelenti, hogy a levelezőszerver átvette a levelet. Hogy
megérkezett-e, az a címzett levelezésén múlik (levélszemét mappa, teli
postafiók).

## Gyakori hibák

| Jelenség | Ok, teendő |
|---|---|
| A Rendszer oldalon: „Küldés: nincs beállítva” | A `config/local.php`-ben nincs `mail` rész, vagy hiányzik a `dsn` vagy a `from`. |
| A Rendszer oldalon: „A config/local.php levelezési beállításai hibásak” | A `dsn` formátuma rossz (például elírás a `smtp://` részben), vagy a `from` nem e-mail-cím. |
| A naplóban „sikertelen”, hitelesítési hibával | Rossz felhasználónév vagy jelszó, vagy különleges karakter kódolatlanul. |
| A naplóban „sikertelen”, kapcsolódási hibával vagy időtúllépéssel | Rossz szerver vagy port. Próbáld a másikat (587 `smtp://`-vel, 465 `smtps://`-vel). Egyes tárhelyek a kimenő levelezést csak a saját szerverükre engedik. |
| Elküldve, de nem érkezik meg | Nézd meg a levélszemét mappát. A feladó legyen a webhely domainjén (lásd fent). |

---

Előző: [7. Kapcsolati űrlap és adatvédelem](07-kapcsolati-urlap.md) ·
[Tartalomjegyzék](README.md)

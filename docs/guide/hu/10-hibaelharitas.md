# 10. Ha valami nem működik

Ez a fejezet a leggyakoribb jelenségeket gyűjti össze, az okukkal és a
teendővel. Ha valami nem működik, először mindig a **Rendszer** oldalt nézd
meg (2. fejezet): a legtöbb beállítási hibát ott látod, a teendővel együtt.

## Az oldal nem jelenik meg

| Amit látsz | Az ok és a megoldás |
|---|---|
| Üres oldal, vagy „Belső hiba történt.” (500-as hiba) | A részleteket a tárhely PHP-hibanaplója tartalmazza (a tárhely felületén, gyakran „Hibanapló” vagy „Error log” néven). A Campanella minden hibát oda ír. Gyakori ok egy elgépelés a `config/local.php`-ben (például hiányzó vessző vagy idézőjel), vagy egy félbemaradt feltöltés. A hibakereső módot ne kapcsold be ehhez: a hibát a naplóban is látod. |
| „A webhely frissítés alatt áll.” | Új verzió került fel, amely az adatbázist is módosítja, de a frissítés még nem futott le. Lépj be, és futtasd a `/admin/upgrade` oldalon (9. fejezet). |
| „A Campanella még nincs telepítve.” | Az adatbázis üres, vagy a `config/local.php` másik adatbázisra (vagy más `prefix`-re) mutat. Telepítés előtt ez rendben van (1. fejezet). Ha az oldal korábban működött, ellenőrizd a `local.php` adatbázis-beállításait. |
| „Az oldal nem található.” | A cím elgépelt, vagy a cikk még piszkozat (a látogatók nem látják). Belépve a piszkozatot is látod: ezért nézd meg kilépve vagy egy privát ablakban. Egy megváltozott útvonal régi címe magától továbbvezet, hacsak nem törölted az átirányítását (3. fejezet). |
| „Nincs jogosultságod az oldal megtekintéséhez.” | Szerkesztőként csak a tartalmakat kezelheted; a többi részhez adminisztrátor kell (6. fejezet). |

## Belépés

| Amit látsz | Az ok és a megoldás |
|---|---|
| „Hibás e-mail-cím vagy jelszó.” | Ellenőrizd a címet és a jelszót (a nagy- és kisbetű számít). Ugyanezt látod akkor is, ha a fiókod le van tiltva: kérdezd meg az adminisztrátort. |
| „Túl sok sikertelen próbálkozás.” | Öt hibás jelszó után legfeljebb 15 percig várnod kell. Ez a fiókodat védi a jelszó találgatása ellen. Ha nem emlékszel a jelszóra, kérj újat (6. fejezet). |
| Nem jön meg az új jelszó linkje | Nézd meg a levélszemét-mappát. Ha az e-mail nincs beállítva (8. fejezet), a link el sem indul: egy másik adminisztrátor beállíthat neked új jelszót. |
| Váratlanul kiléptetett | Két óra tétlenség után, és a belépés után legfeljebb 12 órával a Campanella magától kiléptet. Egy jelszócsere, a letiltás vagy egy másik eszközről a **Kijelentkezés mindenhol máshol** is kiléptet. |
| Egyetlen adminisztrátor sem tud belépni | Ha van parancssor (SSH): a `php bin/campanella user:password <e-mail-cím>` parancs új jelszót állít be (a `--activate` kapcsolóval egy letiltott fiókot újra engedélyez). Ha nincs, a tárhely szolgáltatója vagy egy fejlesztő segíthet. |

## Űrlapok és tartalom

| Amit látsz | Az ok és a megoldás |
|---|---|
| „Az űrlap lejárt. Kérjük, próbáld újra.” | Az űrlap túl sokáig volt nyitva, vagy közben kiléptél (például egy másik lapon). Töltsd újra az oldalt, és küldd el újra. Egy hosszú szöveget előtte másold ki, hogy ne vesszen el. |
| Képfeltöltéskor: „A fájl túl nagy” | A kép nagyobb, mint amennyit a PHP enged. Kicsinyítsd le, vagy a tárhely PHP-beállításaiban növeld az `upload_max_filesize` és a `post_max_size` értékét (2. fejezet, a Rendszer oldal). |
| Képfeltöltéskor: „A kép túl nagy; legfeljebb … megapixeles” | Legfeljebb 25 megapixeles képet lehet feltölteni: kicsinyítsd le (5. fejezet). |
| Egy képformátum nem tölthető fel | A tárhely PHP-jának képfeldolgozója (a gd) nem ismeri. A Rendszer oldal **Képek** része megmutatja, melyik formátum nem megy; a tárhely szolgáltatója tudja bekapcsolni. |
| Egy menüpont nem látszik | Lásd a [4. fejezet](04-menuk.md#ha-a-menü-nem-úgy-jelenik-meg-ahogy-szeretnéd) táblázatát. |
| Frissítés vagy egy változtatás után a régi kinézet látszik | A Rendszer oldalon a **Sablon-gyorsítótár ürítése**, a böngészőben a Ctrl+F5 segít. |
| A keresés nem talál egy oldalt | Az oldal piszkozat vagy időzített; vagy a keresett szó háromnál rövidebb, vagy nagyon gyakori angol szó. Egy frissítés után a régebbi oldalakat előbb a keresésbe kell tenni: a Rendszer oldal **Keresési index** sora jelzi, ha hiányzik valami (2. fejezet). |
| Egy mező, egy Blueprint vagy a „Szűrhető és rendezhető” jelölés nem törölhető | Egy lista használja (feltételben, a sorrendben, oszlopként vagy szűrőként): az üzenet megnevezi. Előbb a listát módosítsd vagy töröld (11. fejezet). Egy Blueprint addig sem törölhető, amíg vannak elemei. |
| Egy lista oldala nem jelenik meg | A listának nincs útvonala, vagy a listákban (**Rendszer → Listák**) hibát jelez, például mert a Blueprintje megváltozott a fájlban. A lista csak a közzétett elemeket mutatja. |
| Hibás címek a megosztási linkekben vagy az e-mailekben | A **Rendszer → Webhely-beállítások** oldalon a **Webcím (URL)** nem a webhely mostani címe (például a HTTPS bekapcsolása vagy egy domaincsere után). Írd át (2. fejezet). |

## A kapcsolati űrlap és az e-mail

| Amit látsz | Az ok és a megoldás |
|---|---|
| A látogató: „Az űrlapot nagyon gyorsan küldted el” | Az űrlap a megnyitása után 3 másodpercen belül érkezett, és a Campanella robotnak vélte. Ha a látogató újra elküldi, megérkezik (7. fejezet). |
| A látogató: „Túl sok üzenet.” | Egy címről óránként legfeljebb 5 üzenet érkezhet. A korlát a kéretlen üzenetek ellen véd; egy óra múlva újra lehet írni. |
| Nem jönnek az e-mailek | Lásd a [8. fejezet](08-e-mail.md#gyakori-hibák) gyakori hibáit, és az e-mail-naplót. |

## Ha nem boldogulsz

- **A Rendszer oldal** egy friss állapotképet ad: a hibát jelző sorok alatt
  ott a teendő. Ha segítséget kérsz, ezt is érdemes elküldeni (a jelszavakat
  és a kulcsokat a Campanella nem mutatja).
- **Hibát** a GitHubon jelenthetsz:
  [github.com/szhajagos/campanella/issues](https://github.com/szhajagos/campanella/issues).
  Írd le, mit csináltál, mit vártál, és mit láttál helyette; a Campanella
  verziószámát a Rendszer oldal mutatja. Jelszót, kulcsot vagy a
  `local.php`-t soha ne tedd ki nyilvánosan.
- **Biztonsági hibát ne nyilvánosan jelents**, hanem a
  [biztonsági leírásban](../../security.md#reporting-a-vulnerability)
  (angolul) leírt módon, hogy a javítás előtt ne lehessen visszaélni vele.

---

Előző: [9. Mentés és frissítés](09-mentes-es-frissites.md) · Következő:
[11. Saját tartalomtípusok](11-sajat-tartalomtipusok.md) · [Tartalomjegyzék](README.md)

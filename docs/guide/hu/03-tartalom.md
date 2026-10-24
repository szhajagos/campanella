# 3. Tartalom

Az oldal tartalmát az admin **Tartalom** részében kezeled. Ez a fejezet a
cikkekről, az oldalakról és a kategóriákról szól: hogyan írod meg, teszed
közzé, időzíted, módosítod és törlöd őket.

## Cikk, oldal, kategória: mi a különbség?

| Fajta | Mire való? | Hol jelenik meg? |
|---|---|---|
| **Cikk** | Hír, bejegyzés, közlemény: aminek dátuma van, és újabbak jönnek utána. | A címlapon és a Hírek listában a legújabb elöl, a saját címén, és a kategóriái oldalán. |
| **Oldal** | Állandó tartalom: Rólunk, Elérhetőség, Adatkezelési tájékoztató. | Csak a saját címén. A látogatók a menüből érik el (4. fejezet). |
| **Kategória** | A cikkek csoportosítása, akár alkategóriákkal. | A saját oldalán, rajta a hozzá tartozó cikkekkel. |

## A lista

A bal oldali menüben a **Cikk** (vagy **Oldal**, **Kategória**) a tartalom
listáját nyitja meg:

![A cikkek listája](../images/hu/content-list.png)

- A **Keresés a címben** mezővel és az **Állapot** szerinti szűréssel
  találod meg, amit keresel. A **Szűrés** gomb alkalmazza.
- A **Kategóriák** szűrővel egy kategória cikkeit nézheted meg. (A saját
  tartalomtípusok listájának oszlopait és szűrőit te választod meg, lásd a
  11. fejezetet.)
- Az oszlopfejlécekre kattintva rendezheted a listát.
- A címre kattintva a szerkesztő oldal nyílik meg, a **Megtekintés** link az
  oldalon mutatja meg (a piszkozatot is, de csak neked).
- Jobb fent az **Új** gombbal hozol létre újat.

Az **Állapot** oszlop megmutatja, hol tart az elem:

| Állapot | Jelentése |
|---|---|
| **Piszkozat** | Még nem látható a webhelyen. Csak a belépett szerkesztők látják. |
| **Időzített** | Közzé van téve, de egy későbbi időpontban jelenik meg (lásd lent). |
| **Publikált** | Látható a webhelyen. |

## Új cikk írása

Az **Új** gomb után ez az űrlap jelenik meg:

![Új cikk űrlapja](../images/hu/article-form.png)

- **Cím:** kötelező. Ez a cikk címe, és ebből készül a webcíme is.
- **Bevezető:** egy-két mondat a cikkről. A címlapon és a listákban ez
  jelenik meg a cím alatt, és ez lesz az oldal leírása a keresőkben is.
- **Törzsszöveg:** maga a cikk, a szövegszerkesztővel (lásd lent).
- **Kategóriák:** jelöld be, melyikbe tartozik. Több is lehet, vagy egy sem.
- **Szerző:** ha üresen hagyod, te leszel a szerző.
- **Útvonal:** a cikk címe a webhelyen, például `/nyari-nyitvatartas`. Ha
  üresen hagyod, a címből készül: ékezetek nélkül, kötőjelekkel. Később is
  módosíthatod: a régi cím ilyenkor továbbvezet az újra (lásd lent,
  [Átirányítások](#átirányítások)).

Alul két gomb van:

- **Létrehozás:** elmenti piszkozatként. Még senki nem látja, nyugodtan
  folytathatod később.
- **Létrehozás és közzététel:** elmenti, és azonnal meg is jelenteti.

### A szövegszerkesztő

![A szövegszerkesztő eszköztára](../images/hu/editor.png)

Az eszköztár gombjai balról jobbra:

| Gomb | Mit csinál? |
|---|---|
| ¶ | A bekezdés fajtája: normál szöveg, **címsorok** (2–4. szint), idézet, kód. A cikk címe az 1. szint, ezért a cikken belüli alcímek a 2. szinttel kezdődnek. |
| **B**, *I*, áthúzás, alsó és felső index | Betűstílusok. |
| Felsorolás, számozott lista | Listák. A kis nyíllal a jelölés fajtája is választható. |
| Lánc | Link. Jelöld ki a szöveget, kattints rá, és írd be a címet. |
| Kép | Kép feltöltése a szövegbe (5. fejezet). |
| Táblázat, vízszintes vonal | Táblázat, illetve elválasztó vonal beszúrása. |
| Radír | A kijelölt szöveg formázásának törlése. |
| Visszavonás, újra | Az utolsó módosítás visszavonása vagy megismétlése. |
| `</>` | A szöveg HTML-kódja (haladóknak). |
| Négyzet | Teljes képernyős szerkesztés. |

Néhány tudnivaló:

- **Beillesztés Wordből vagy egy weboldalról:** a szerkesztő a felesleges
  formázást eltávolítja. Más webhelyről bemásolt képek nem maradnak meg:
  ezeket töltsd fel (5. fejezet).
- **Mentéskor** a Campanella minden szöveget átszűr, és eltávolít belőle
  mindent, ami nem biztonságos vagy nem illik az oldal kinézetébe (szkriptek,
  egyedi stílusok, színek, betűtípusok). Ezért a mentett szöveg kicsit
  egyszerűbb lehet, mint amit beillesztettél. Ez szándékos: így marad
  egységes és biztonságos az oldal.

## Közzététel

Mentés után a szerkesztő oldal jobb oldalán a **Megjelenés** doboz mutatja,
hol tart a cikk, és itt teheted közzé:

![Egy piszkozat közzététele](../images/hu/publication-draft.png)

- **Azonnal:** hagyd üresen a **Megjelenés ideje** mezőt, és kattints a
  **Közzététel** gombra.
- **Később (időzítve):** adj meg egy jövőbeli időpontot, és kattints a
  **Közzététel** gombra. A cikk addig nem látható, és a megadott időpontban
  magától megjelenik. Az időpont az oldal időzónájában értendő (a doboz alján
  látszik, például Europe/Budapest).

Egy időzített cikknél a doboz így néz ki:

![Egy időzített cikk](../images/hu/publication-scheduled.png)

- Az **Időpont módosítása** gombbal másik időpontot adhatsz meg.
- A **Visszavonás** gomb visszateszi piszkozatba: eltűnik a webhelyről (ha már
  megjelent), de nem törlődik.

Ha az űrlapon módosítottál valamit, de még nem mentetted, a doboz figyelmeztet:
a közzétételi gombok csak a közzétételt végzik el, a módosításokat nem
mentik. Előbb a **Mentés** gombbal mentsd el őket.

Egy közzétett cikk módosításai a **Mentés** után azonnal látszanak a
webhelyen.

## Oldalak

Az oldalak ugyanígy működnek, de nincs bevezetőjük, kategóriájuk és
szerzőjük, és nem jelennek meg a címlapon. Egy új oldalt általában a menübe
is felveszel, különben a látogatók nem találják meg (4. fejezet).

Az adatkezelési tájékoztatót is oldalként érdemes megírni, például
`/adatkezeles` útvonallal (7. fejezet).

## Kategóriák

![A kategóriák listája](../images/hu/category-list.png)

A kategóriák fát alkotnak: egy kategóriának lehetnek alkategóriái.

- A **+ alelem** link egy új alkategóriát hoz létre az adott kategória alatt.
  A szerkesztő oldalon a **Szülő** mezővel is áthelyezheted.
- A sor végén lévő **↑** és **↓** nyilakkal változtatod a sorrendet. A
  **Súly** oszlop mutatja a sorrendet: a kisebb kerül előre.
- Egy kategória oldala a hozzá tartozó cikkeket mutatja, az alkategóriáiéval
  együtt.
- Egy kategória, amelynek alkategóriái vannak, nem törölhető: a törlés oldala
  felsorolja őket, ezeket előbb töröld vagy helyezd át.

## Keresés a webhelyen

A webhely fejlécében a **Keresés…** mezővel a látogatók a közzétett cikkek,
oldalak, kategóriák (és a saját tartalomtípusaid elemei) között kereshetnek:

![Egy keresés eredménye](../images/hu/site-search.png)

- A keresés a címben és a szövegben keres, a címben talált szó többet ér. Minden
  beírt szónak szerepelnie kell; egy szó eleje is elég (*kert* megtalálja a
  *kertben* szót is), és az ékezetek nem számítanak (*kirandulas* is jó).
- A legalább háromjegyű szavak számítanak; a nagyon gyakori angol szavakat
  (például *the*, *and*) a keresés kihagyja.
- A találatok alatt a szöveg egy részlete áll, a megtalált szavak kiemelve.
- A piszkozatokat és az időzítetteket a keresés nem találja meg, a megjelenésük
  után igen. Egy mentett módosítás azonnal kereshető.
- Ha egy látogató (vagy egy program) egy percen belül nagyon sokat keres, a
  keresés egy percre megáll neki. Ez a webhelyet védi a túlterheléstől.

A keresés oldala a `/search` címen van; a keresőmotorok nem listázzák.

## Átirányítások

Ha egy cikk vagy oldal **útvonala megváltozik**, a régi címe nem vész
el: aki a régi linkre kattint (egy másik oldalról, egy keresőből, egy
könyvjelzőből), az újra kerül. Ezt a Campanella magától intézi.

A **Rendszer → Átirányítások** oldal sorolja fel ezeket:

![Az átirányítások listája](../images/hu/redirects.png)

- Az **automatikus** sorokat egy útvonal-változás hozta létre: mindig az elem
  mostani címére vezetnek, akkor is, ha az később újra változik. Az elem
  törlésével megszűnnek.
- Az **Új átirányítás** dobozban kézzel is megadhatsz egyet, például egy régi
  webhely címeiről (`/regi-oldal`) a mostaniakra (`/uj-oldal`). Olyan címről,
  amely egy létező oldal, nem lehet: az oldal mindig előbbre való.
- A **Használat** oszlop mutatja, hányszor vezetett tovább. Egy már nem kellő
  átirányítást a **Törlés** gombbal szüntethetsz meg.

## Törlés

A szerkesztő oldalon a **Törlés** gomb előbb rákérdez:

![A törlés megerősítése](../images/hu/delete.png)

A **Végleges törlés** után az elem **nem állítható vissza**. Ha csak el
akarod rejteni a webhelyről, inkább vond vissza a közzétételét
(**Visszavonás**): így megmarad, és később újra közzéteheted.

A szerkesztők nem törölhetnek, ez az adminisztrátorok joga.

## A példatartalom eltávolítása

Ha a telepítéskor példatartalmat is kértél, ezeket töröld vagy írd át, mielőtt
az oldal nyilvános lesz: a cikkeket (Neumann János, Digitális Agora …), a
**Tudomány** és **Campanella** kategóriát, a **Rólunk** oldalt, az
**Eseményeket** (11. fejezet, a végén), és a főmenü megfelelő pontjait
(4. fejezet).

---

Előző: [2. Első lépések](02-elso-lepesek.md) · Következő:
[4. Menük](04-menuk.md) · [Tartalomjegyzék](README.md)

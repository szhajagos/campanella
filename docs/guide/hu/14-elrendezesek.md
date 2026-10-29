# 14. Elrendezések és régiók

Egy oldal **elrendezése** a szerkezete: milyen részekből, **régiókból** áll, és
azok hol vannak. A régiókba **komponensek** kerülnek (13. fejezet), egymás alá,
a sorrendjükben. A Campanellában két elrendezés van:

| Elrendezés | Régiói |
|---|---|
| **Egy hasáb** (az alapértelmezett) | Fejléc, Navigáció, Tartalom, Lábléc |
| **Oldalsávval** | Ugyanezek, és egy **Oldalsáv** a tartalom mellett (kisebb képernyőn alatta) |

A témák további elrendezéseket hozhatnak; ezek az adminban ugyanígy
választhatók. Az oldal saját tartalma (a cikk, a lista, a keresés találatai)
mindig a **Tartalom** régió elején van; amit oda teszel, az alatta jelenik meg.

## A fejléc és a lábléc

A 0.3.4-től a webhely fejléce és lábléce is komponensekből áll. A telepítés és
a 0.3.4-re frissítés ezeket elkészíti, úgy, ahogy a fejléc addig kinézett:

| Régió | Komponens | Mit mutat? |
|---|---|---|
| Fejléc | **A webhely neve** | A webhely nevét, a címlapra mutató linkként. |
| Navigáció | **Főmenü** | A főmenüt (4. fejezet). |
| | **Keresőmező** | A keresést (3. fejezet). |
| | **Admin gomb** | Csak annak, aki beléphet az adminba. |
| | **Belépés gomb** | Belépés, bejelentkezve kilépés. |
| Lábléc | **Lábléc** | Egy szövegrészletet: © 2026 Campanella · Campanella 0.3.4. |

Ezeket ugyanúgy módosíthatod, mint bármelyik komponenst: a **Megjelenés →
Komponensek** listában a címükre kattintva. A lábléc szövegét a **Tartalom →
Szövegrészlet** listában, a *Lábléc* szövegrészletnél írod át. A benne lévő
szögletes zárójeles **tokenek** helyén megjelenéskor az értékük áll:

| Token | Mivé lesz? |
|---|---|
| `[site:name]` | A webhely neve (**Rendszer → Webhely-beállítások**). |
| `[site:slogan]` | A webhely szlogenje. |
| `[site:url]` | A webhely címe. |
| `[date:year]` | Az aktuális év. |
| `[campanella:version]` | A Campanella verziója. |

A szövegrészlet szerkesztője alatt a **Tokenek a szövegben** részt kinyitva
ezeket mindig látod.

> Ha egy régióból kiveszel mindent, az a rész üres marad: üres lábléc nem
> jelenik meg. A komponensek a **Komponensek** listában megmaradnak, bármikor
> visszateheted őket.

## Az elrendezés kiválasztása

A **Megjelenés → Elrendezések** oldalon:

![Az elrendezések oldala](../images/hu/layouts.png)

- **A webhely elrendezése:** ezt kapja minden oldal, amelynek nincs sajátja.
- **A Blueprintek saját elrendezése:** egy tartalomtípus oldalai (például a
  cikkek) a webhelyétől eltérő elrendezést kaphatnak. Itt a cikkek kapják az
  **Oldalsávval** elrendezést. A listák, a keresés és a belépés oldala a
  webhelyét kapja.
- **Kis képernyőn:** régiónként beállíthatod, hogyan látszik telefonon vagy
  tableten. **Mindig látszik**; **összecsukva** egy szélesség alatt (egy
  gombbal nyílik, mint a navigáció); vagy **rejtve** egy szélesség alatt. Az
  *alapérték* az elrendezés saját beállítása.

A **Mentés** gombbal érvényesek a webhelyen.

## A régiók és a komponensek sorrendje

A **Megjelenés → Régiók** oldalon egy elrendezés régióit látod, mindegyikben a
komponenseit, a sorrendjükben. A fülekkel válthatsz az elrendezések között.

![A régiók oldala](../images/hu/regions.png)

- **Fel**, **Le:** a komponens helye a régión belül. A navigációban például a
  menü után jön a kereső, aztán a gombok.
- **Kivesz:** a komponens kikerül a régióból, de megmarad (a Komponensek
  listában).
- **Hozzáad:** a régió alján választhatsz egy komponenst; a régió végére kerül.
  Ha addig másik régióban volt, a lista azt is mutatja („most: Fejléc”), és
  onnan átkerül.

A régiók neve közös az elrendezésekben: ami az **Oldalsáv** régióban van, az
minden oldalsávos elrendezésben megjelenik, ami a **Fejléc**-ben, az mindegyikben.

## Hol jelenjen meg?

Egy komponens űrlapján, a **Hol jelenik meg** részben (13. fejezet):

![Hol jelenik meg egy komponens](../images/hu/component-place.png)

- **Régió:** melyikben legyen. *Egyik régióban sem:* csak ott jelenik meg, ahol
  a téma sablonja a gépi nevével meghívja.
- **Mely oldalakon:** ha semmi sincs megadva, mindenhol.
  - **A címlapon:** a címlapon.
  - **Ezeken az útvonalakon:** soronként egy, `/`-rel kezdve. A `*` bármit
    jelent: a `/hirek/*` minden oldal a `/hirek/` alatt (maga a `/hirek` nem).
  - **Ezen Blueprintek objektumainak oldalain:** például minden cikk oldalán.
  - **Soha ezeken az útvonalakon:** a kivételek, ugyanígy soronként.

  A címlap, az útvonalak és a Blueprintek összeadódnak: elég, ha az oldal az
  egyiknek megfelel. A kivételek mindig döntenek. Hogy **ki** láthatja, azt a
  komponens **Ki láthatja** mezője mondja meg (mindenki, a bejelentkezettek,
  egyes szerepkörök).

Az *Elérhetőség* doboz itt az oldalsávba került, csak a cikkek oldalain:

![Egy cikk az oldalsávban az elérhetőséggel](../images/hu/site-sidebar.png)

## Ha valami nem úgy jelenik meg

| Jelenség | Ok és teendő |
|---|---|
| Egy komponens nem látszik | Nincs régióban (az űrlapján), az oldal nem felel meg a feltételeinek (**Mely oldalakon**), vagy a látogató nem láthatja (**Ki láthatja**). Az is lehet, hogy az oldal elrendezésében nincs olyan régió (például oldalsáv az *Egy hasáb* elrendezésben). |
| Az oldalsáv üres, nem látszik | Az oldal elrendezése *Egy hasáb*: a **Megjelenés → Elrendezések** oldalon adj az oldal tartalomtípusának *Oldalsávval* elrendezést. |
| Elfogyott a fejléc, nincs menü | A *Főmenü* komponenst kivették a régióból: a **Régiók** oldalon tedd vissza a Navigáció régióba. Ha törölték, készíts egy újat: forrás a *Főmenü*, megjelenítés a *Navigáció*. |
| A lábléc régi szöveget mutat | A lábléc a *Lábléc* szövegrészlet: azt írd át. |
| Telefonon nem látszik egy rész | A régió kis képernyőn rejtve van: a **Megjelenés → Elrendezések** oldalon állítsd *Mindig látszik*-ra vagy *összecsukva*-ra. |

A témák készítőinek szóló leírás:
[PHP API, 29. fejezet](../../php-api/29-layouts.md).

---

Előző: [13. Komponensek](13-komponensek.md) · [Tartalomjegyzék](README.md)

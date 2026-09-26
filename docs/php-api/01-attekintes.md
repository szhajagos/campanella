# 1. Áttekintés

## Az alapgondolat

A Campanellában nincsenek előre rögzített tartalomtípusok (cikk, oldal, média…)
PHP-osztályként. Egyetlen általános objektum van, a `CampanellaObject`, és hogy
mire képes, azt a rá szerelt **capability-k** döntik el. A „típus” egy
elnevezett capability-csomag, a **Blueprint**, ami konfiguráció, nem kód.

```
Blueprint "article" = Titled + Textual + Routable + Publishable  (+ egyedi mező: lead)
```

## Rétegek (MVC + Service)

```
HTTP kérés
  → Router                       melyik controller?
  → Controller                   vékony: paraméterek be, válasz ki
  → Service / QueryEngine        üzleti logika, jogosultság
  → ObjectRepository             betöltés, mentés (Model)
  → Controller
  → Presentation + Twig          megjelenítés (View)
  → HTTP válasz
```

| Réteg | Fő osztályok | Fejezet |
|---|---|---|
| Model | `CampanellaObject`, `Capability`, `Blueprint`, `ObjectRepository`, `Query`, `QueryEngine`, `AccessPolicy` | 2–5, 6 |
| Service | `ObjectService` | 6 |
| Controller | `ObjectController`, `QueryController` | 7 |
| View | `Presentation`, `CampanellaTwigExtension`, sablonok | 7 |
| Infrastruktúra | `Kernel`, `Container`, `Connection`, `Router`, CLI | 7–9 |

## Alapelvek, amelyekre az API épül

**Data Mapper.** Az objektum nem ment magáról. `$object->save()` nincs, helyette
`ObjectRepository::save($object)` vagy, jogosultság-ellenőrzéssel,
`ObjectService::update(...)`.

**Capability mint adapter.** A capability viselkedése az objektumon keresztül
érhető el:

```php
$object->as(Publishable::class)->publish();
```

**Egyedi mezőnevek.** Minden mezőnév rendszerszinten egyedi, ezért az objektum
mezői egyszerűen név szerint érhetők el: `$object->get('title')`.

**Tárolási szabály.** A mező vagy a capability saját táblájában él (lekérdezhető),
vagy az objektum JSON `data` oszlopában (csak tárolás). JSON-mezőre szűrni és
rendezni nem lehet; a `QueryCompiler` ezt hibával jelzi.

**Jogosultság nélküli lekérdezés nincs.** A `QueryEngine` minden hívása
megköveteli az `Actor`-t, és a jogosultsági feltételeket még az SQL előtt fűzi a
lekérdezéshez. Amit valaki nem láthat, az le sem jön az adatbázisból.

**Megváltoztathatatlan értékobjektumok.** A `Query`, `Request`, `Field`,
`Blueprint`, `Actor`, `ResultSet` és a feltételek nem módosíthatók; a `Query`
metódusai új példányt adnak vissza.

**Időzónák.** Minden időpont UTC-ben tárolódik és `DateTimeImmutable`
formában kerül elő. A megjelenítés a `timezone` beállítás (alapból
`Europe/Budapest`) szerint alakítja át.

**SQL egy helyen.** SQL csak a `Connection`-ben, a `SchemaBuilder`-ben és a
`QueryCompiler`-ben keletkezik. A cél a MariaDB 10.6+ és MySQL 8.0+ közös
részhalmaza.

## Kivételek

| Kivétel | Mikor | Alaposztály |
|---|---|---|
| `Campanella\Model\ValidationException` | Hiányzó kötelező mező, foglalt egyedi érték | `RuntimeException` |
| `Campanella\Access\AccessDeniedException` | Az `Actor` nem végezheti el a műveletet | `RuntimeException` |
| `Campanella\Http\HttpException` | HTTP-hiba (pl. 404) a controllerben | `RuntimeException` |
| `Campanella\Capability\CapabilityException` | Hibás capability-, Blueprint- vagy scope-definíció, hiányzó capability | `LogicException` |
| `Campanella\Query\QueryException` | Ismeretlen vagy nem lekérdezhető mező, hibás operátor | `LogicException` |
| `OutOfBoundsException` | Nem létező mező olvasása vagy írása | (PHP) |

A `LogicException` leszármazottai programozási hibát jeleznek: ezeket javítani
kell, nem elkapni. A `RuntimeException` leszármazottai futás közben, adatból
eredhetnek, ezeket a hívó kezeli (pl. hibaüzenetet mutat).

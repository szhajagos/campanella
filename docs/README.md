# Campanella dokumentáció

Ez a mappa a Campanella fejlesztői dokumentációja. A kóddal együtt él és
verziózódik: minden új funkció ugyanabban a commitban frissíti az érintett
fejezetet.

| Rész | Tartalom | Állapot |
|---|---|---|
| [PHP API](php-api/README.md) | Az osztályok, szerződések és bővítési pontok leírása példákkal | 0.0.1-nek megfelel |
| [HTTP API](http-api/README.md) | A webes végpontok: a mostani HTML-útvonalak és a tervezett JSON API | HTML: kész · JSON: tervezet |
| [Változásnapló](../CHANGELOG.md) | Verziónként mi került be, mi változott, mi szűnt meg | folyamatos |

A telepítést és az indítást a projekt gyökerében lévő [README](../README.md) írja le.

## Stabilitási jelölések

A PHP API minden eleme mellett szerepel, mennyire lehet rá építeni:

| Jelölés | Jelentés |
|---|---|
| **Nyilvános** | Modulok és saját kód nyugodtan használhatja. Változását a CHANGELOG jelzi. |
| **Belső** | A mag része, közvetlenül nem érdemes rá építeni: figyelmeztetés nélkül változhat. |

A Campanella a [szemantikus verziózást](https://semver.org/lang/hu/) követi. A
0.x verziókban még minden kiadás hozhat nem visszafelé kompatibilis változást;
ezeket a CHANGELOG **Megváltozott** és **Megszűnt** szakasza mindig felsorolja.
Az 1.0-tól a nyilvános API csak főverzió-váltással változhat meg
visszafelé nem kompatibilis módon.

A HTTP API saját verziót kap az URL-ben (`/api/v1/…`). Visszafelé nem
kompatibilis változás csak új verzióban (`/api/v2/…`) jelenhet meg.

## Teendők minden új funkciónál

Egy funkció akkor kész, ha a dokumentáció is követi:

1. **Kód és PHPDoc.** A nyilvános osztályok és metódusok dokumentációs
   megjegyzést kapnak.
2. **PHP API fejezet.** Az érintett fejezetbe kerül az új osztály, metódus vagy
   bővítési pont, legalább egy példával. Új terület új fejezetet kap, és
   bekerül a [tartalomjegyzékbe](php-api/README.md).
3. **HTTP API.** Ha a funkció webes végpontot ad vagy módosít, a
   [HTTP API](http-api/README.md) leírása is frissül, és a végpont állapota
   „tervezett”-ről „kész”-re vált.
4. **CHANGELOG.** A változás a [CHANGELOG](../CHANGELOG.md) „Kiadatlan”
   szakaszába kerül.
5. **Ellenőrzés.**

   ```bash
   composer test          # tesztek
   composer analyse       # PHPStan
   composer docs:check    # van-e dokumentálatlan nyilvános osztály vagy metódus
   ```

A `docs:check` a kódból gyűjti ki a nyilvános osztályokat és metódusokat, és
jelzi, ha valamelyik nem szerepel a `docs/php-api` fejezeteiben. Így a
dokumentáció nem maradhat le észrevétlenül a kódtól.

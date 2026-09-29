# 11. Felhasználók és bejelentkezés

*0.0.3 óta.*

A felhasználó is egy általános objektum: a `user` Blueprint a `Titled`
(név) és az `Authenticatable` capability-kből áll. Az utóbbi magával hozza
az `Identifiable`-t (e-mail-cím). A bejelentkezett felhasználó `Actor`-ként,
a szerepköreivel érvényesül a jogosultsági szabályokban.

```
user = Titled + Identifiable + Authenticatable
         név     e-mail         jelszó-hash, fiókállapot, szerepkörök
```

## Capability-k

### Identifiable

`Campanella\Capability\Identifiable` · név: `identifiable` · tábla: `cap_identifiable`

| Mező | Típus | Tárolás | |
|---|---|---|---|
| `email` | String(254) | Table | kötelező, egyedi, rejtett |

| Metódus | Leírás |
|---|---|
| `email(): string` | |
| `setEmail(string $email): void` | Normalizálva állítja be |
| `static normalize(string $email): string` | Kisbetűs, szóközök nélküli alak: `' Anna@Example.HU '` → `'anna@example.hu'` |
| `prepareForSave()` | Mentés előtt normalizál |
| `validate()` | Érvénytelen formátumra: `['email' => 'érvénytelen e-mail-cím']` |

### Authenticatable

`Campanella\Capability\Authenticatable` · név: `authenticatable` · tábla: `cap_authenticatable` · függ: `Identifiable`

| Mező | Típus | Tárolás | |
|---|---|---|---|
| `password_hash` | String | Table | kötelező, rejtett |
| `account_status` | String | Table | indexelt, alapértelmezés: `active` |
| `roles` | StringList | Table | pl. `["administrator"]` |

| Metódus / konstans | Leírás |
|---|---|
| `MIN_PASSWORD_LENGTH` (10), `MAX_PASSWORD_BYTES` (72) | Jelszószabály. A 72 bájtos felső határ a bcrypt korlátja |
| `setPassword(string $password): void` | Hash-eli (`password_hash`, `PASSWORD_DEFAULT`). Szabálysértésre `ValidationException` (`password` kulccsal) |
| `verifyPassword(string $password): bool` | `password_verify` |
| `needsRehash(): bool`, `rehash(string $password): void` | Ha a PHP erősebb alapértelmezésre vált, belépéskor a hash automatikusan frissül |
| `status(): AccountStatus`, `isActive(): bool`, `block()`, `activate()` | Fiókállapot |
| `roles(): list<string>`, `setRoles(array $roles)`, `hasRole(string $role): bool` | Szerepkörök (`^[a-z][a-z0-9_]{0,31}$`) |
| `validate()` | Érvénytelen szerepkörnévre hibát ad |

`AccountStatus` (`enum: string`): `Active = 'active'`, `Blocked = 'blocked'`.

A **rejtett** (`hidden`) mezők (`password_hash`, `email`) a sablonokból nem
érhetők el `{{ user.email }}` alakban; PHP-ból a `get()` olvassa őket. Így egy
sablon véletlenül sem teszi közzé az e-mail-címet vagy a hash-t.

### Authorable

`Campanella\Capability\Authorable` · név: `authorable` · saját tábla nincs

| Kapcsolat | Számosság | Cél |
|---|---|---|
| `author` | One | `Identifiable` capability-vel rendelkező objektum |

| Metódus | Leírás |
|---|---|
| `authorId(): ?int` | |
| `setAuthor(CampanellaObject\|int $user): void` | |

Az `ObjectService::create()` a bejelentkezett felhasználót (`ActorKind::User`)
automatikusan szerzőnek állítja be, ha a szerző még nincs megadva. A cikkoldalon a
`_relations` részlet „Szerző: …” formában jeleníti meg.

## Szerepkörök (DefaultPolicy)

| Szerepkör | Láthat | Létrehozhat, módosíthat, publikálhat | Törölhet | Felhasználót kezelhet |
|---|---|---|---|---|
| `administrator` | mindent | mindent | igen | igen |
| `editor` | mindent, a piszkozatokat is | tartalmat | nem | nem |
| (nincs, vagy névtelen) | ami nem Publishable, vagy publikált | nem | nem | nem |

A konstansok: `Actor::ADMINISTRATOR`, `DefaultPolicy::EDITOR`.

## AuthService

`Campanella\Auth\AuthService` · **Nyilvános** · konténer: `AuthService::class`

| Metódus | Leírás |
|---|---|
| `attempt(Request $request, string $email, string $password): LoginResult` | Belépési kísérlet: guardok, korlátozás, jelszó, fiókállapot; sikeres esetben be is léptet |
| `login(Request $request, CampanellaObject $user): void` | Beléptetés ellenőrzés nélkül (pl. egy második lépcső után). Új munkamenet-azonosító, új CSRF-token |
| `logout(): void` | Munkamenet törlése |
| `currentUser(Request $request): ?CampanellaObject` | A bejelentkezett felhasználó. Névtelen látogatónál nem indít munkamenetet. Letiltott vagy törölt fióknál kiléptet |
| `currentActor(Request $request): Actor` | Ugyanez `Actor`-ként; a `Kernel` ezt adja a controllereknek |
| `static actorFor(CampanellaObject $user): Actor` | `ActorKind::User`, azonosító, szerepkörök, név |
| `findUserByEmail(string $email): ?CampanellaObject` | |
| `guards(): list<LoginGuard>` | A beállított guardok |
| `GENERIC_ERROR` | `'Hibás e-mail-cím vagy jelszó.'` |

**Biztonsági viselkedés:**

- Hibás e-mail-cím és hibás jelszó esetén ugyanaz az üzenet, és a futásidő is
  közel azonos (nem létező fióknál is lefut egy jelszó-hash). Kívülről így nem
  derül ki, létezik-e egy fiók.
- Sikertelen próbálkozás után a `Throttle` számol: alapból 5 próbálkozás
  e-mail-cím és IP-cím páronként, és 20 próbálkozás IP-címenként, 15 percen belül.
  Siker esetén az e-mail-cím és IP-cím pár számlálója nullázódik.
- A letiltott fiók helyes jelszóval sem léphet be („A fiók le van tiltva.”), a
  már belépett munkamenete pedig a következő kérésnél megszűnik.

`LoginResult` (`final readonly class`): `$success`, `$user`, `$error`;
`static success(CampanellaObject $user)`, `static failure(string $error)`.

## Bővítés: LoginGuard

`Campanella\Auth\LoginGuard` · **Nyilvános** · `interface`

A jelszó ellenőrzése **előtt** futó kiegészítő védelem: honeypot, CAPTCHA,
IP-tiltólista stb.

| Metódus | Leírás |
|---|---|
| `check(Request $request): ?string` | Hibaüzenet elutasításkor, `null` továbbengedéskor |
| `fields(): string` | Az űrlapba kerülő kiegészítő HTML (vagy üres szöveg) |

A guardok listája a `config/app.php` `auth.guards` kulcsában van, és sorban
futnak; az első elutasítás megállítja a belépést.

```php
'auth' => [
    'guards' => [
        HoneypotGuard::class,
        App\Auth\CaptchaGuard::class,   // saját guard
    ],
],
```

### HoneypotGuard

`Campanella\Auth\Guard\HoneypotGuard` · alapból bekapcsolva

Egy ember számára láthatatlan `website` mező. Ha a robot kitölti, a kérés
elutasítódik, ugyanazzal az üzenettel, mint a hibás jelszónál. `FIELD =
'website'`; `fields()` adja az űrlapba kerülő HTML-t, amelyet a képernyőolvasók
és a billentyűzetes navigáció is átugranak.

### Kétlépcsős azonosítás (később)

A jelszó ellenőrzése (`attempt()`) és a tényleges beléptetés (`login()`) külön
lépés. Egy későbbi kétlépcsős azonosítás egy saját capability lesz a
felhasználón (pl. a TOTP-kulccsal), és a két lépés közé illeszkedik: sikeres
jelszó után nem léptet be azonnal, hanem a második lépcsőt kéri. Lásd a
[ROADMAP](../../ROADMAP.md)-ot.

## Munkamenet

`Campanella\Http\Session` · **Nyilvános** · konténer: `Session::class`

Lusta indítású munkamenet, tétlenségi időkorláttal.

| Metódus | Leírás |
|---|---|
| `__construct(SessionStorage $storage, int $idleTimeout = 7200)` | |
| `resume(Request $request): bool` | Csak akkor folytatja, ha a kérés hozott munkamenet-cookie-t; a lejártat megszünteti |
| `start(Request $request): void` | Indítás íráshoz (belépés, CSRF-token) |
| `isStarted(): bool` | |
| `get(string $key, mixed $default = null)`, `set(string $key, mixed $value)`, `remove(string $key)` | A `set()` indítatlan munkamenetnél `LogicException`-t dob |
| `regenerate(): void`, `destroy(): void` | Új azonosító, illetve törlés a cookie-val együtt |

A névtelen látogatók nem kapnak cookie-t. Munkamenet csak a belépési oldalon
(a CSRF-token miatt) és belépés után indul. Ha a kérésnek van munkamenete, a
`Kernel` a válaszhoz `Cache-Control: private, no-store` fejlécet ad, hogy
köztes gyorsítótár ne tárolja a személyre szabott oldalt.

### SessionStorage

`Campanella\Http\SessionStorage` · **Nyilvános** · `interface`: `exists()`,
`start()`, `isStarted()`, `get()`, `set()`, `remove()`, `regenerate()`, `destroy()`.

| Megvalósítás | Mire |
|---|---|
| `NativeSessionStorage(string $name = 'campanella_session', bool\|string $secure = 'auto', int $lifetime = 7200)` | Élesben: a PHP munkamenet-kezelése. HttpOnly és SameSite=Lax cookie, szigorú mód, HTTPS-en Secure cookie (`'auto'`), a cookie útvonala alkönyvtáras telepítésnél az alkönyvtár |
| `ArraySessionStorage` | Tesztekhez: memóriában. `generation()` a csere-számláló, `endRequest()` egy kérés végét játssza el |

## CSRF

`Campanella\Security\Csrf` · **Nyilvános** · konténer: `Csrf::class`

| Metódus | Leírás |
|---|---|
| `token(Request $request): string` | A munkamenet tokenje (64 hexadecimális karakter); szükség esetén munkamenetet indít |
| `isValid(Request $request): bool` | A POST `_csrf` mezője egyezik-e (`hash_equals`) |
| `rotate(): void` | Új token; belépéskor automatikusan fut |

Sablonban minden POST-űrlapba: `{{ csrf_field() }}`. A mező neve a `FIELD`
konstans (`_csrf`).

## Throttle

`Campanella\Security\Throttle` · **Nyilvános** · konténer: `Throttle::class`

Próbálkozások korlátozása kulcsonként, időablakkal, a `cc_throttle` táblában.
A kulcsnak csak a SHA-256 hash-e tárolódik.

| Metódus | Leírás |
|---|---|
| `tooManyAttempts(string $key, int $maxAttempts): bool` | |
| `hit(string $key, int $decaySeconds): int` | Egy próbálkozás; visszaadja a számot |
| `availableIn(string $key): int` | Hány másodperc múlva lehet újra próbálkozni |
| `clear(string $key): void` | |

Más funkciók (pl. egy későbbi kapcsolatfelvételi űrlap) is használhatják.

## Webes felület

`Campanella\Controller\AuthController` · handler: `auth`

| Útvonal | Leírás |
|---|---|
| `GET /belepes` | Belépési űrlap (`page/login.html.twig`). Belépett felhasználót továbbküld |
| `POST /belepes` | Belépés. Siker: 303-as átirányítás a `vissza` mezőben megadott oldalra (vagy a főoldalra). Hiba: az űrlap újra, 422-vel (lejárt CSRF-token esetén 400-zal) |
| `POST /kilepes` | Kilépés (CSRF-tokennel), majd átirányítás a főoldalra. GET kérésre nem léptet ki |

`static safeTarget(string $target): string`: a visszairányítás célja csak a
saját oldalon belüli útvonal lehet (`/…`, de nem `//…`). Minden mást `'/'`-re
cserél, így a belépés nem használható idegen oldalra irányításra.

Sablonban: `{{ current_user() }}` a bejelentkezett felhasználó (vagy `null`),
`{{ csrf_field() }}` a rejtett tokenmező.

## Parancssor

| Parancs | Leírás |
|---|---|
| `user:create <e-mail> [--name="Név"] [--role=administrator,editor]` | Új felhasználó. A jelszót a parancs kéri be, rejtve, kétszer |
| `user:password <e-mail>` | Új jelszó |
| `user:password <e-mail> --block` / `--activate` | Fiók letiltása vagy újraengedélyezése |
| `user:list` | Felhasználók listája |

A rendszer alapértelmezett fiókot vagy jelszót soha nem hoz létre; az első
adminisztrátort ezzel kell létrehozni:

```bash
php bin/campanella user:create te@example.hu --name="A Neved" --role=administrator
```

Ha a bemenet nem terminál (pl. szkriptből: `echo "jelszo" | php bin/campanella user:create …`),
a parancs egyszer olvassa be a jelszót.

Segédosztályok: `Campanella\Cli\Input` (`ask()`, `secret()`, `isInteractive()`),
`Campanella\Cli\Args` (`static parse(array $args)`, `argument()`, `option()`,
`flag()`), `Campanella\Cli\PasswordPrompt` (`static ask(Input, Output): ?string`).
A parancsosztályok (`UserCreateCommand`, `UserPasswordCommand`, `UserListCommand`)
**belsők**.

## Konfiguráció

| Kulcs | Alapérték | Leírás |
|---|---|---|
| `session.name` | `'campanella_session'` | A cookie neve |
| `session.idle_timeout` | `7200` | Tétlenségi időkorlát másodpercben |
| `session.secure` | `'auto'` | `'auto'`: csak HTTPS-en Secure; `true` / `false`: kényszerítve |
| `auth.max_attempts` | `5` | Sikertelen próbálkozás e-mail-cím és IP-cím páronként |
| `auth.max_attempts_per_ip` | `20` | Sikertelen próbálkozás IP-címenként |
| `auth.decay_seconds` | `900` | Az időablak |
| `auth.guards` | `[HoneypotGuard::class]` | LoginGuard-osztályok |

**Proxy mögött:** a HTTPS felismerése a `HTTPS` szerverváltozón múlik. Ha a
tárhely egy fordított proxy mögött HTTP-n szolgálja ki a PHP-t, állítsd a
`session.secure` értékét `true`-ra, különben a cookie Secure jelző nélkül megy ki.
A `Request::$ip` a `REMOTE_ADDR`; proxy mögött ez a proxy címe lehet, és akkor az
IP-alapú korlátozás mindenkire közösen érvényes.

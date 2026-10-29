# 30. Tokens

*Since 0.3.4.* A **token** is a placeholder in a text, replaced by its value
when the text is shown: `© [date:year] [site:name]` shows as `© 2026 Campanella`.
In 0.3.4 tokens are replaced in the texts of the **snippets** only (the Blueprints
`tokens.blueprints` names), so no article or page that happens to hold a
`[x:y]` changes; the footer the upgrade makes is a snippet with tokens
([chapter 29](29-layouts.md)).

| Token | Value |
|---|---|
| `[site:name]` | The site's name (*Site settings*) |
| `[site:slogan]` | Its slogan |
| `[site:url]` | Its address |
| `[date:year]` | The current year, in the site's time zone |
| `[campanella:version]` | Campanella's version |

A token is written `[prefix:name]`, in lowercase letters, digits and `_`
(`TokenRegistry::PATTERN`). Its **value is escaped** as HTML wherever it is put,
in the text or in an attribute; a token no provider knows, or one written
otherwise (`[Site:Name]`, `[site: name]`), **stays as it is**. The admin's form of
a snippet lists the tokens under the editor.

| Class | | |
|---|---|---|
| `TokenProvider` | **Public** (implement it) | A group of tokens: a prefix, its tokens, their values |
| `TokenRegistry` | **Public** | The providers; replaces the tokens of a text |
| `SiteTokens`, `DateTokens`, `CampanellaTokens` | **Public** | The core's providers: `site`, `date`, `campanella` |

All are in `Campanella\Token`.

## Settings (`config/app.php`, `tokens`)

| Key | Default | |
|---|---|---|
| `tokens.providers` | `TokenRegistry::BUILTIN` | The providers' classes; a module adds its own: `[...TokenRegistry::BUILTIN, MyTokens::class]` |
| `tokens.blueprints` | `['snippet']` | The Blueprints whose texts (the `body` filter, so every display of them) have their tokens replaced |

## A provider

```php
use Campanella\Token\TokenProvider;

final class ShopTokens implements TokenProvider
{
    public function prefix(): string { return 'shop'; }

    /** name => label (a message key or text), for the list under the editor */
    public function tokens(): array { return ['phone' => 'Phone number']; }

    public function value(string $name): ?string
    {
        return $name === 'phone' ? '+36 1 234 5678' : null;   // null: not a token of this provider
    }
}
```

The registry makes a provider with its factory: the Kernel's gives it from the
container if the container has its class, else with `new`.

## TokenRegistry

`Campanella\Token\TokenRegistry` · **Public**

`new TokenRegistry(array $classes = self::BUILTIN, array $blueprints = [], ?Closure $factory = null)`

| Method | |
|---|---|
| `replace(string $html): string` | The tokens of an HTML text replaced by their escaped values; unknown ones are left |
| `value(string $prefix, string $name): ?string` | A token's value, or null |
| `tokens(): list<array{token, label}>` | Every token, for the editor's list |
| `appliesTo(string $blueprint): bool` | Whether this Blueprint's texts have their tokens replaced |
| `providers(): array<string, TokenProvider>` | prefix ⇒ provider; `InvalidArgumentException` for a class that is not a provider, or a prefix used twice |

## In templates

`{{ object|body }}` replaces the tokens of a snippet's text. For any other text,
`{{ '© [date:year] [site:name]'|tokens }}`: the text escaped, with its tokens
replaced.

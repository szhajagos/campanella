<?php

declare(strict_types=1);

namespace Campanella\Token;

use Closure;

/**
 * The tokens (since 0.3.4): `[site:name]`, `[date:year]` … in a text, replaced by their
 * values when it is shown. A value is escaped as HTML; a token no provider knows stays as it
 * is written, so an ordinary `[x:y]` in a text is left alone.
 *
 *     $tokens->replace('<p>© [date:year] [site:name]</p>');   // <p>© 2026 Campanella</p>
 *
 * In 0.3.4 the core replaces tokens only in the text of the Blueprints that `tokens.blueprints`
 * names (the snippets), so no other text changes.
 */
final class TokenRegistry
{
    /** A token as written: `[prefix:name]`. */
    public const string PATTERN = '/\[([a-z][a-z0-9_]{0,31}):([a-z][a-z0-9_]{0,31})\]/';

    /** The core's providers. */
    public const array BUILTIN = [SiteTokens::class, DateTokens::class, CampanellaTokens::class];

    /** @var array<string, TokenProvider>|null */
    private ?array $providers = null;

    /**
     * @param list<class-string<TokenProvider>> $classes
     * @param list<string> $blueprints The Blueprints whose texts have their tokens replaced
     * @param (Closure(class-string): object)|null $factory Makes a provider of a class
     */
    public function __construct(
        private readonly array $classes = self::BUILTIN,
        private readonly array $blueprints = [],
        private readonly ?Closure $factory = null,
    ) {
    }

    /**
     * @return array<string, TokenProvider> prefix => provider
     * @throws \InvalidArgumentException for a class that is not a provider, or a prefix used twice
     */
    public function providers(): array
    {
        if ($this->providers === null) {
            $providers = [];
            foreach ($this->classes as $class) {
                if (!is_subclass_of($class, TokenProvider::class)) {
                    throw new \InvalidArgumentException("{$class} is not a TokenProvider.");
                }
                $provider = $this->factory === null ? new $class() : ($this->factory)($class);
                if (!$provider instanceof TokenProvider) {
                    throw new \InvalidArgumentException("{$class} is not a TokenProvider.");
                }
                $prefix = $provider->prefix();
                if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $prefix) !== 1 || isset($providers[$prefix])) {
                    throw new \InvalidArgumentException("Invalid or repeated token prefix: {$prefix}");
                }
                $providers[$prefix] = $provider;
            }
            $this->providers = $providers;
        }

        return $this->providers;
    }

    /**
     * Every token, for the list shown under the editor.
     *
     * @return list<array{token: string, label: string}>
     */
    public function tokens(): array
    {
        $list = [];
        foreach ($this->providers() as $prefix => $provider) {
            foreach ($provider->tokens() as $name => $label) {
                $list[] = ['token' => '[' . $prefix . ':' . $name . ']', 'label' => $label];
            }
        }

        return $list;
    }

    /** A token's value, or null for one no provider knows. */
    public function value(string $prefix, string $name): ?string
    {
        return ($this->providers()[$prefix] ?? null)?->value($name);
    }

    /** The tokens of an HTML text replaced by their escaped values; unknown ones are left. */
    public function replace(string $html): string
    {
        if (!str_contains($html, '[')) {
            return $html;
        }

        return (string) preg_replace_callback(self::PATTERN, function (array $m): string {
            $value = $this->value($m[1], $m[2]);

            return $value === null ? $m[0] : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }, $html);
    }

    /** Whether the texts of this Blueprint have their tokens replaced. */
    public function appliesTo(string $blueprint): bool
    {
        return in_array($blueprint, $this->blueprints, true);
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Site;

use Campanella\Capability\MediaFile;
use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Settings\Settings;

/**
 * The site's own settings (since 0.1.1), edited on the admin's Settings page:
 *
 *   name         the site's name (page titles, the header, Open Graph)
 *   slogan       a short line under the name on the front page
 *   description  the default meta description (where a page has none of its own)
 *   url          the site's address, e.g. https://example.hu (canonical URLs,
 *                Open Graph, sitemap.xml); empty: not set, those are left out
 *   share_image  the image (an image object's ID) shown when a page is shared
 *   indexing     whether search engines may index the site
 *
 * A setting saved in the admin wins; one never saved comes from the `site` section
 * of the configuration file (config/app.php, config/local.php). The other keys of
 * that section (e.g. a theme's own) are passed through to templates unchanged.
 */
final class SiteSettings
{
    public const string PREFIX = 'site.';

    /** The settings edited in the admin. */
    public const array KEYS = ['name', 'slogan', 'description', 'url', 'share_image', 'indexing'];

    /** The longest values (characters). */
    public const array MAX_LENGTH = ['name' => 100, 'slogan' => 200, 'description' => 300, 'url' => 255];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * @param array<string, mixed> $defaults The configuration file's `site` section
     * @param ObjectRepository|null $repository To check that the share image is an image
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly array $defaults = [],
        private readonly ?ObjectRepository $repository = null,
    ) {
    }

    /**
     * Every value: the saved ones over the configuration file's, typed (`share_image`:
     * ?int, `indexing`: bool, the others strings), plus the configuration file's other keys.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        $values = $this->defaults;
        foreach (self::KEYS as $key) {
            $stored = $this->settings->get(self::PREFIX . $key);
            $values[$key] = self::typed($key, $stored ?? ($this->defaults[$key] ?? null));
        }

        return $this->values = $values;
    }

    public function name(): string
    {
        return (string) $this->values()['name'];
    }

    /** The site's address without a trailing slash (e.g. https://example.hu/campanella), or '' if not set. */
    public function url(): string
    {
        return (string) $this->values()['url'];
    }

    /** The share image's object ID, if set. */
    public function shareImage(): ?int
    {
        $id = $this->values()['share_image'];

        return is_int($id) ? $id : null;
    }

    /** Whether search engines may index the site (default: yes). */
    public function indexing(): bool
    {
        return (bool) $this->values()['indexing'];
    }

    /**
     * A path of the site as an absolute URL (`/hirek` → `https://example.hu/hirek`), or null
     * while the site's address is not set. Site paths are stored as they are read
     * (decoded), so their characters outside URL syntax (accented letters, spaces, `%`)
     * are percent-encoded.
     */
    public function absolute(string $path): ?string
    {
        $url = $this->url();
        if ($url === '') {
            return null;
        }
        $encoded = (string) preg_replace_callback(
            '#[^A-Za-z0-9._~!$&\'()*+,;=:@/-]#',
            static fn (array $m): string => rawurlencode($m[0]),
            ltrim($path, '/'),
        );

        return $url . '/' . $encoded;
    }

    /**
     * Saves the settings. Every key of KEYS is expected (a missing one: empty; for
     * `indexing`: '1' or '0').
     *
     * @param array<string, string> $input
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $values = [];
        $errors = [];
        foreach (['name', 'slogan', 'description', 'url', 'share_image'] as $key) {
            if (!mb_check_encoding($input[$key] ?? '', 'UTF-8')) {
                $errors[$key] = new Message('validation.invalid_encoding');
                $input[$key] = '';
            }
        }
        foreach (['name', 'slogan', 'description'] as $key) {
            $value = trim(preg_replace('/\s+/u', ' ', $input[$key] ?? '') ?? '');
            if (mb_strlen($value) > self::MAX_LENGTH[$key]) {
                $errors[$key] ??= new Message('validation.value_too_long', ['max' => self::MAX_LENGTH[$key]]);
            }
            $values[$key] = $value;
        }
        if ($values['name'] === '') {
            $errors['name'] ??= new Message('validation.required');
        }

        $url = self::normalizeUrl($input['url'] ?? '');
        if ($url === null) {
            $errors['url'] ??= new Message('settings.invalid_url');
        }
        $values['url'] = $url ?? '';

        $image = trim($input['share_image'] ?? '');
        if ($image !== '' && (!ctype_digit($image) || !$this->isImage((int) $image))) {
            $errors['share_image'] ??= new Message('settings.invalid_image');
        }
        $values['share_image'] = $image;
        $values['indexing'] = ($input['indexing'] ?? '1') === '1' ? '1' : '0';

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $stored = [];
        foreach ($values as $key => $value) {
            $stored[self::PREFIX . $key] = $value;
        }
        $this->settings->set($stored);
        $this->values = null;
    }

    /**
     * Saves the site's address, unless one is already saved or set in the configuration
     * file (the browser installer: the address it was opened at). An invalid address is
     * ignored.
     */
    public function rememberUrl(string $url): void
    {
        $url = self::normalizeUrl($url);
        // An address in the configuration file (e.g. CAMPANELLA_SITE_URL behind a proxy that
        // sends an internal Host) is the operator's: the request's must not override it.
        $configured = self::normalizeUrl(is_string($this->defaults['url'] ?? null) ? $this->defaults['url'] : '');
        if ($url !== null && $url !== '' && ($configured === null || $configured === '') && $this->settings->get(self::PREFIX . 'url') === null) {
            $this->settings->set([self::PREFIX . 'url' => $url]);
            $this->values = null;
        }
    }

    /** Forgets the values read (e.g. after another process saved them). */
    public function reset(): void
    {
        $this->settings->reset();
        $this->values = null;
    }

    /**
     * A site address in its stored form: http(s), a host, optionally a port and a
     * folder; no user name, query or fragment; no trailing slash. '' for an empty
     * input; null if it is not such an address.
     */
    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > self::MAX_LENGTH['url'] || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || str_ends_with($url, '?') || str_ends_with($url, '#')) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true) || !self::isHost($host)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port === 0) {
            return null;
        }
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null; // the default port is not written
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && preg_match('#^(/([A-Za-z0-9._~!$&\'()*+,;=:@-]|%[0-9A-Fa-f]{2})+)+\z#', $path) !== 1) {
            return null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path;
    }

    /**
     * The address the request was made to (scheme, Host header and the installation's
     * folder), e.g. as a suggestion for the site's address; null if the Host header is
     * not a valid host name.
     */
    public static function originOf(Request $request): ?string
    {
        $host = strtolower(trim($request->headers['host'] ?? ''));
        $port = '';
        if (preg_match('/^(\[[0-9a-f:.]+\]|[^:]+)(?::(\d{1,5}))?$/', $host, $m) !== 1 || !self::isHost($m[1])) {
            return null;
        }
        if (isset($m[2])) {
            $port = ':' . $m[2];
        }
        $url = self::normalizeUrl(($request->secure ? 'https' : 'http') . '://' . $m[1] . $port . $request->basePath);

        return $url === '' ? null : $url;
    }

    private static function isHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (strlen($host) > 253
            || preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\z/', $host) !== 1) {
            return false;
        }
        // A host whose last part is a number is an IPv4 address: only a valid one (no 999.1, 0x7f.1).
        $last = substr((string) strrchr('.' . $host, '.'), 1);

        return !ctype_digit($last) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function isImage(int $id): bool
    {
        if ($this->repository === null) {
            return true;
        }
        $object = $this->repository->find($id);

        return $object !== null && $object->has(MediaFile::class);
    }

    private static function typed(string $key, mixed $value): mixed
    {
        return match ($key) {
            'share_image' => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null,
            'indexing' => $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOL),
            'url' => self::normalizeUrl(is_string($value) ? $value : '') ?? '',
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}

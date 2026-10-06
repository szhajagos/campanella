<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Core\Version;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\Migration\MigrationException;
use Campanella\Database\Migration\Migrator;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\I18n\Message;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;
use Campanella\View\Presentation;

/**
 * `<admin>/upgrade`: runs the upgrade from the browser (missing tables, then the
 * pending migrations after a backup), for web hosts without a command line.
 *
 * Who may run it: a logged-in user of the System page's roles
 * (AdminAccess::allowsSystem()), or anyone who enters the upgrade key of
 * `config/local.php` (`upgrade.key`). The key is for when logging in does not
 * work until the upgrade has run (e.g. the users' tables change); it is off by
 * default, and attempts are throttled like logins.
 *
 * While an upgrade is needed, every other page answers 503 (the Kernel), except
 * logging in and out and this page.
 */
final class UpgradeController implements Controller
{
    /** The shortest upgrade key accepted (a shorter one is ignored). */
    public const int MIN_KEY_LENGTH = 20;

    public const int MAX_KEY_ATTEMPTS = 5;

    public const int KEY_DECAY_SECONDS = 900;

    public const string CONTENT_SECURITY_POLICY = AdminController::CONTENT_SECURITY_POLICY;

    public function __construct(
        private readonly Installer $installer,
        private readonly Migrator $migrator,
        private readonly DatabaseBackup $backup,
        private readonly AdminAccess $access,
        private readonly Csrf $csrf,
        private readonly Throttle $throttle,
        private readonly Presentation $presentation,
        private readonly ?string $key = null,
    ) {
    }

    /** The configured key, if long enough to be used. */
    public static function usableKey(mixed $key): ?string
    {
        return is_string($key) && strlen($key) >= self::MIN_KEY_LENGTH ? $key : null;
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        if (!$this->installer->isInstalled()) {
            throw new HttpException(503, 'error.not_installed');
        }
        $admin = !$actor->isAnonymous() && $this->access->allowsSystem($actor);
        $needed = $this->installer->needsUpgrade();
        $context = [
            'needed' => $needed,
            'admin' => $admin,
            'logged_in' => !$actor->isAnonymous(),
            'key_enabled' => self::usableKey($this->key) !== null,
            'key_suggestion' => bin2hex(random_bytes(20)),
            'login_url' => '/belepes?vissza=' . rawurlencode($this->access->path('upgrade')),
            'action' => $this->access->path('upgrade'),
            'system_url' => $this->access->path('system'),
            'error' => null,
            'result' => null,
        ];

        if ($request->isPost() && $needed) {
            return $this->run($request, $admin, $context);
        }

        return $this->page($context + $this->details($admin));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function run(Request $request, bool $admin, array $context): Response
    {
        $context += $this->details(true);
        if (!$this->csrf->isValid($request)) {
            return $this->page(['error' => new Message('auth.form_expired'), 'details' => $admin] + $context, 400);
        }
        if (!$admin) {
            $refused = $this->checkKey($request);
            if ($refused !== null) {
                return $this->page(['error' => $refused, 'details' => false] + $context, 403);
            }
        }

        // An upgrade must not stop halfway because the visitor closed the page.
        @ignore_user_abort(true);
        @set_time_limit(0);

        $this->installer->install();
        $file = null;
        if ($request->postString('no_backup') !== '1') {
            try {
                $file = $this->backup->create();
            } catch (\RuntimeException) {
                // The path is shown, not the details (they are in the error log).
                return $this->page(['error' => new Message('upgrade.backup_failed', ['folder' => $this->backup->directory()])] + $context, 500);
            }
        }

        $log = [];
        try {
            $results = $this->migrator->run(
                static function ($migration) use (&$log): void {
                    $log[] = '→ ' . $migration->id() . ': ' . $migration->description();
                },
                static function (string $line) use (&$log): void {
                    $log[] = '    ' . $line;
                },
            );
        } catch (MigrationException $e) {
            error_log((string) ($e->getPrevious() ?? $e));

            return $this->page([
                'error' => $e->reason,
                'cause' => $e->getPrevious()?->getMessage(),
                'log' => $log,
                'backup_file' => $file === null ? null : basename($file),
            ] + $this->details(true) + $context, 500);
        }

        return $this->page([
            'needed' => $this->installer->needsUpgrade(),
            'result' => $results,
            'log' => $log,
            'backup_file' => $file === null ? null : basename($file),
        ] + $this->details(true) + $context);
    }

    /** Null if the key is right; otherwise why it was refused. */
    private function checkKey(Request $request): ?Message
    {
        $key = self::usableKey($this->key);
        if ($key === null) {
            return new Message('upgrade.not_allowed');
        }
        $throttleKey = 'upgrade-key|' . $request->ip;
        if ($this->throttle->tooManyAttempts($throttleKey, self::MAX_KEY_ATTEMPTS)) {
            return new Message('upgrade.too_many', ['minutes' => (int) ceil($this->throttle->availableIn($throttleKey) / 60)]);
        }
        if (!hash_equals($key, $request->postString('key'))) {
            $this->throttle->hit($throttleKey, self::KEY_DECAY_SECONDS);

            return new Message('upgrade.wrong_key');
        }

        return null;
    }

    /**
     * What is pending (only for those who may run it).
     *
     * @return array<string, mixed>
     */
    private function details(bool $allowed): array
    {
        if (!$allowed) {
            return ['details' => false];
        }

        return [
            'details' => true,
            'schema' => ['database' => $this->installer->systemValue('schema_version'), 'code' => Version::SCHEMA],
            'pending' => array_map(
                static fn ($m): array => ['id' => $m->id(), 'description' => $m->description()],
                $this->migrator->pending(),
            ),
            'backup_folder' => $this->backup->directory(),
            'backup_writable' => self::writable($this->backup->directory()),
        ];
    }

    /** Whether the folder can be written (or created). */
    private static function writable(string $directory): bool
    {
        $existing = $directory;
        while (!is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        return is_writable($existing);
    }

    /** @param array<string, mixed> $context */
    private function page(array $context, int $status = 200): Response
    {
        $html = $this->presentation->render('@core/admin/upgrade.html.twig', $context + ['title' => 'upgrade.title']);

        return Response::html($html, $status)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
    }
}

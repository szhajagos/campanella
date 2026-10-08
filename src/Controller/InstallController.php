<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Auth\AuthService;
use Campanella\Capability\Authenticatable;
use Campanella\Core\Version;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Database\UnsupportedUpgradeException;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\I18n\Message;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Campanella\Security\FileThrottle;
use Campanella\System\SystemCheck;
use Campanella\View\Presentation;
use Closure;

/**
 * Installing from the browser (/install), for web hosts without a command line:
 * the requirements, the tables, and the first administrator. Since 0.1.0.
 *
 * Open only while the site has no user at all (not installed, or installed with
 * `install --sql` in phpMyAdmin), and only with the install key set in
 * `config/local.php` (`install.key`, at least MIN_KEY_LENGTH characters): without
 * it, anyone who finds a freshly uploaded site could install it as their own.
 * Wrong keys are limited per address and in total (FileThrottle: the database
 * cannot be used yet). Once a user exists, the page answers 404.
 */
final class InstallController implements Controller
{
    /** The page's address (since 0.1.0). */
    public const string PATH = '/install';

    public const int MIN_KEY_LENGTH = 20;

    public const int MAX_KEY_ATTEMPTS = 5;

    /** Wrong keys from all addresses together (against an attacker with many addresses). */
    public const int MAX_KEY_ATTEMPTS_TOTAL = 50;

    public const int KEY_DECAY_SECONDS = 900;

    /** The Content-Security-Policy of the page: as strict as the admin's. */
    public const string CONTENT_SECURITY_POLICY = AdminController::CONTENT_SECURITY_POLICY;

    /** The name of the database lock held while installing (two submissions at once). */
    private const string LOCK = 'cmp_install';

    /**
     * @param array<string, string> $writable Folders that must be writable: shown name (relative, so the
     *        page reveals nothing of the server) => absolute path
     * @param Closure(): void $seed Creates the sample content
     */
    public function __construct(
        private readonly Installer $installer,
        private readonly Connection $db,
        private readonly ObjectRepository $repository,
        private readonly AuthService $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly FileThrottle $throttle,
        private readonly Presentation $presentation,
        private readonly AdminAccess $access,
        private readonly ?string $key,
        private readonly array $writable,
        private readonly Closure $seed,
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
        $database = $this->database();
        if ($database['open'] === false) {
            throw HttpException::notFound(); // installed, with users: nothing to do here
        }

        $checks = $this->requirements($database);
        $context = [
            'checks' => $checks,
            'ready' => array_filter($checks, static fn (array $c): bool => !$c['ok']) === [],
            'key_enabled' => $this->key !== null,
            'key_suggestion' => bin2hex(random_bytes(20)),
            'min_key' => self::MIN_KEY_LENGTH,
            'min_password' => Authenticatable::MIN_PASSWORD_LENGTH,
            'installed' => $database['installed'],
            'action' => self::PATH,
            'input' => ['name' => '', 'email' => '', 'seed' => true],
            'errors' => [],
            'error' => null,
        ];
        if (!$request->isPost() || !$context['key_enabled'] || !$context['ready']) {
            return $this->page($context);
        }

        $input = [
            'name' => trim($request->postString('name')),
            'email' => trim($request->postString('email')),
            'seed' => $request->postString('seed') === '1',
        ];
        $context['input'] = $input;
        if (!$this->csrf->isValid($request)) {
            return $this->page(['error' => new Message('auth.form_expired')] + $context, 400);
        }
        $refused = $this->checkKey($request);
        if ($refused !== null) {
            return $this->page(['error' => $refused] + $context, 403);
        }
        $errors = $this->validate($input, $request->postString('password'), $request->postString('password_again'));
        if ($errors !== []) {
            return $this->page(['errors' => $errors, 'error' => new Message('admin.form.invalid')] + $context, 422);
        }

        // An upgrade must not stop halfway because the visitor closed the page.
        @ignore_user_abort(true);
        @set_time_limit(0);
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(:name, 10)', ['name' => self::LOCK]) !== 1) {
            return $this->page(['error' => new Message('install.busy')] + $context, 409);
        }
        try {
            if ($this->database()['open'] !== true) {
                throw HttpException::notFound(); // someone else was faster
            }
            $this->installer->install();
            $user = $this->repository->create('user', ['title' => $input['name'], 'email' => $input['email']]);
            $auth = $user->as(Authenticatable::class);
            $auth->setPassword($request->postString('password'));
            $auth->setRoles(['administrator']);
            $this->repository->save($user);
            if ($input['seed']) {
                ($this->seed)();
            }
        } catch (UnsupportedUpgradeException $e) {
            return $this->page(['error' => $e->reason] + $context, 409);
        } catch (ValidationException $e) {
            return $this->page(['errors' => $e->errors, 'error' => new Message('admin.form.invalid')] + $context, 422);
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK]);
        }

        $this->auth->login($request, $user);
        $this->flash->add(Flash::SUCCESS, new Message('install.done'));

        return Response::redirect($request->basePath . $this->access->path(), 303);
    }

    /**
     * The database: reachable? installed? Open for installing: when it has no user yet.
     *
     * @return array{connected: bool, error: ?string, version: ?string, installed: bool, open: bool}
     */
    private function database(): array
    {
        try {
            $version = $this->db->serverVersion();
            $installed = $this->installer->isInstalled();
            $open = !$installed || $this->installer->userCount() === 0;

            return ['connected' => true, 'error' => null, 'version' => $version, 'installed' => $installed, 'open' => $open];
        } catch (\PDOException $e) {
            // Only the error code: the message could name the database user or host.
            return ['connected' => false, 'error' => (string) ($e->errorInfo[1] ?? $e->getCode()), 'version' => null, 'installed' => false, 'open' => true];
        }
    }

    /**
     * What installing needs: PHP, its extensions, the database, writable folders.
     *
     * @param array{connected: bool, error: ?string, version: ?string, installed: bool, open: bool} $database
     * @return list<array{label: string, value: string, ok: bool, hint: ?Message}>
     */
    private function requirements(array $database): array
    {
        $checks = [[
            'label' => 'admin.system.php',
            'value' => PHP_VERSION,
            'ok' => version_compare(PHP_VERSION, SystemCheck::MIN_PHP, '>='),
            'hint' => null,
        ]];
        $missing = array_values(array_filter(SystemCheck::REQUIRED_EXTENSIONS, static fn (string $e): bool => !extension_loaded($e)));
        $checks[] = [
            'label' => 'admin.system.group.required',
            'value' => $missing === [] ? 'OK' : implode(', ', $missing),
            'ok' => $missing === [],
            'hint' => null,
        ];
        $checks[] = [
            'label' => 'admin.system.database',
            'value' => $database['connected'] ? (string) $database['version'] : '–',
            'ok' => $database['connected'],
            'hint' => $database['connected'] ? null : new Message('install.no_database', ['code' => (string) $database['error']]),
        ];
        foreach ($this->writable as $name => $folder) {
            $ok = is_dir($folder) ? is_writable($folder) : is_writable(dirname($folder));
            $checks[] = [
                'label' => 'install.writable',
                'value' => $name,
                'ok' => $ok,
                'hint' => $ok ? null : new Message('install.not_writable'),
            ];
        }

        return $checks;
    }

    private function checkKey(Request $request): ?Message
    {
        $key = (string) $this->key;
        $perAddress = 'install-key|' . $request->ip;
        if ($this->throttle->tooManyAttempts($perAddress, self::MAX_KEY_ATTEMPTS)
            || $this->throttle->tooManyAttempts('install-key', self::MAX_KEY_ATTEMPTS_TOTAL)) {
            $wait = max($this->throttle->availableIn($perAddress), $this->throttle->availableIn('install-key'));

            return new Message('upgrade.too_many', ['minutes' => max(1, (int) ceil($wait / 60))]);
        }
        if (!hash_equals($key, $request->postString('key'))) {
            $this->throttle->hit($perAddress, self::KEY_DECAY_SECONDS);
            $this->throttle->hit('install-key', self::KEY_DECAY_SECONDS);

            return new Message('install.wrong_key');
        }

        return null;
    }

    /**
     * The administrator's data, checked before anything is installed.
     *
     * @param array{name: string, email: string, seed: bool} $input
     * @return array<string, Message>
     */
    private function validate(array $input, string $password, string $again): array
    {
        $errors = [];
        if ($input['name'] === '') {
            $errors['title'] = new Message('validation.required');
        }
        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = new Message('validation.invalid_email');
        }
        if (mb_strlen($password, 'UTF-8') < Authenticatable::MIN_PASSWORD_LENGTH) {
            $errors['password'] = new Message('validation.password_too_short', ['min' => Authenticatable::MIN_PASSWORD_LENGTH]);
        } elseif (strlen($password) > Authenticatable::MAX_PASSWORD_BYTES) {
            $errors['password'] = new Message('validation.password_too_long', ['max' => Authenticatable::MAX_PASSWORD_BYTES]);
        } elseif (!hash_equals($password, $again)) {
            $errors['password_again'] = new Message('install.password_mismatch');
        }

        return $errors;
    }

    /** @param array<string, mixed> $context */
    private function page(array $context, int $status = 200): Response
    {
        $html = $this->presentation->render('@core/admin/install.html.twig', $context + ['title' => 'install.title', 'version' => Version::CAMPANELLA]);

        return Response::html($html, $status)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
    }
}

<?php

declare(strict_types=1);

namespace Campanella\Core;

use Campanella\Cli\Output;
use Campanella\Event\Action;
use Campanella\Event\EventDispatcher;
use Campanella\Mail\MailCheck;
use Campanella\Mail\Mailer;
use Campanella\Settings\Settings;
use Campanella\Site\MetaBuilder;
use Campanella\Site\SiteSettings;
use Campanella\Site\SiteValues;
use Campanella\Service\UserService;
use Campanella\Admin\MailPages;
use Campanella\Admin\SettingsPage;
use Campanella\Admin\StructurePages;
use Campanella\Admin\UserPages;

use Campanella\Cli\SeedCommand;

use Campanella\Security\FileThrottle;

use Campanella\Controller\InstallController;

use Campanella\Controller\UpgradeController;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\DefaultPolicy;
use Campanella\Http\Flash;
use Campanella\Controller\AdminController;
use Campanella\Admin\AdminAccess;
use Campanella\Admin\Form\ObjectForm;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Auth\AuthService;
use Campanella\Auth\LoginGuard;
use Campanella\Controller\AuthController;
use Campanella\Controller\Controller;
use Campanella\Controller\ObjectController;
use Campanella\Controller\QueryController;
use Campanella\Controller\SiteController;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\Migration\CoreMigrations;
use Campanella\Database\Migration\MigrationRegistry;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Sync\SchemaSync;
use Campanella\Database\Sync\SyncCheck;
use Campanella\Http\HttpException;
use Campanella\Http\NativeSessionStorage;
use Campanella\Http\Request;
use Campanella\Http\SecurityHeaders;
use Campanella\Http\TrustedProxies;
use Campanella\Http\SitePaths;
use Campanella\Http\Response;
use Campanella\Http\Router;
use Campanella\Http\Session;
use Campanella\I18n\Translator;
use Campanella\Menu\MenuBuilder;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ObjectRepository;
use Campanella\Query\QueryCompiler;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;
use Campanella\Tree\SiblingOrder;
use Campanella\Service\ObjectService;
use Campanella\View\CampanellaTwigExtension;
use Campanella\View\Presentation;
use Campanella\View\Theme;
use Campanella\Html\HtmlSanitizer;
use Campanella\Media\DeleteMediaFile;
use Campanella\Media\ImageProcessor;
use Campanella\Media\MediaCheck;
use Campanella\Media\MediaService;
use Campanella\Media\MediaStorage;
use Campanella\Media\MediaUsage;
use Campanella\Media\ResponsiveImages;
use Campanella\System\SecurityCheck;
use Campanella\System\SiteCheck;
use Campanella\System\SystemCheck;
use Campanella\System\TemplateCache;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Campanella\View\TemplatePolicy;
use Twig\Loader\FilesystemLoader;

/**
 * Assembles the system and serves a request.
 *
 *   HTTP request → Router → Controller → Service / Query Engine (Model)
 *                → Presentation + Twig (View) → HTTP response
 */
final class Kernel
{
    private ?Container $container = null;
    private string $basePath = '';
    private ?Request $request = null;

    public function __construct(private readonly string $rootDir)
    {
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function container(): Container
    {
        return $this->container ??= $this->services();
    }

    public function handle(Request $request): Response
    {
        // Behind a trusted proxy: the visitor's own address and HTTPS state (since 0.1.0).
        try {
            $request = $this->container()->get(TrustedProxies::class)->apply($request);
        } catch (\Throwable $e) {
            return (new SecurityHeaders())->apply($this->failure($e), $request);
        }
        $response = $this->noindex($this->dispatch($request));
        try {
            return $this->container()->get(SecurityHeaders::class)->apply($response, $request);
        } catch (\Throwable) {
            // A broken setting must not hide the page; the strict policy still applies.
            return (new SecurityHeaders())->apply($response, $request);
        }
    }

    /**
     * While search engines are asked not to index the site (Site settings), every
     * response without its own X-Robots-Tag says noindex (since 0.1.1).
     */
    private function noindex(Response $response): Response
    {
        try {
            if (!isset($response->headers['X-Robots-Tag']) && !$this->container()->get(SiteSettings::class)->indexing()) {
                return $response->withHeader('X-Robots-Tag', 'noindex');
            }
        } catch (\Throwable) {
            // The settings cannot be read: the response as it is.
        }

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        // The Twig extension reads the URL prefix from here per request, so the
        // container (and the services overridden in it) persists between requests.
        $this->basePath = $request->basePath;
        $this->request = $request;
        // The settings are read again for every request (one may have saved them since).
        try {
            $this->container()->get(SiteSettings::class)->reset();
            $this->container()->get(SiteValues::class)->reset();
        } catch (\Throwable) {
            // A broken configuration is reported below, by the route's handling.
        }

        try {
            $container = $this->container();
            $route = $container->get(Router::class)->match($request);

            // While an upgrade is needed, only logging in and out and the upgrade page work:
            // the code may not match the database yet.
            if (!in_array($route->handler, self::DURING_UPGRADE, true) && $this->upgradeNeeded()) {
                $admin = $container->get(AdminAccess::class);
                $isAdmin = $request->path === $admin->path() || str_starts_with($request->path, $admin->path() . '/');

                return $this->errorResponse(503, $isAdmin ? 'error.needs_upgrade_admin' : 'error.needs_upgrade', ['path' => $request->basePath . $admin->path('upgrade')])
                    ->withHeader('Retry-After', '300');
            }

            /** @var Controller $controller */
            $controller = $container->get('controller.' . $route->handler);
            try {
                $actor = $container->get(AuthService::class)->currentActor($request);
            } catch (\Throwable $e) {
                // The upgrade page must open even if the users cannot be read before the upgrade.
                if ($route->handler !== 'upgrade' && $route->handler !== 'install') {
                    throw $e;
                }
                $actor = Actor::anonymous();
            }

            $response = $controller->handle($request, $route, $actor);

            // With a session (logged in, or a form CSRF token) the response is
            // personalized: an intermediate cache (proxy, CDN) must not store it.
            return $container->get(Session::class)->isStarted()
                ? $response->withHeader('Cache-Control', 'private, no-store')
                : $response;
        } catch (HttpException $e) {
            return $this->errorResponse($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /** The handlers that work while an upgrade is needed. */
    private const array DURING_UPGRADE = ['upgrade', 'auth', 'install'];

    /** Installed, and an upgrade is needed (a database error: false, handled elsewhere). */
    private function upgradeNeeded(): bool
    {
        try {
            return $this->container()->get(Installer::class)->needsUpgrade();
        } catch (\Throwable) {
            return false;
        }
    }

    private function services(): Container
    {
        $c = new Container();
        $root = $this->rootDir;

        $c->set(Config::class, static fn (): Config => Config::load($root . '/config'));

        $c->set(Connection::class, static fn (Container $c): Connection => Connection::fromConfig(
            $c->get(Config::class)->get('database'),
        ));

        $c->set(CapabilityRegistry::class, static fn (Container $c): CapabilityRegistry => new CapabilityRegistry(
            $c->get(Config::class)->get('capabilities', []),
        ));

        $c->set(BlueprintRegistry::class, static fn (Container $c): BlueprintRegistry => new BlueprintRegistry(
            $c->get(CapabilityRegistry::class),
            require $root . '/config/blueprints.php',
        ));

        // The settings edited in the admin (since 0.1.1), over the configuration file's.
        $c->set(Settings::class, static fn (Container $c): Settings => new Settings($c->get(Connection::class)));
        $c->set(SiteSettings::class, static fn (Container $c): SiteSettings => new SiteSettings(
            $c->get(Settings::class),
            (array) $c->get(Config::class)->get('site', []),
            $c->get(ObjectRepository::class),
        ));
        $c->set(SiteValues::class, static fn (Container $c): SiteValues => new SiteValues(
            static fn (): array => $c->get(SiteSettings::class)->values(),
        ));

        $c->set(MetaBuilder::class, static fn (Container $c): MetaBuilder => new MetaBuilder(
            $c->get(SiteSettings::class),
            $c->get(QueryEngine::class),
            $c->get(MediaStorage::class),
            (string) $c->get(Config::class)->get('media.url', '/media'),
        ));

        $c->set(SitePaths::class, static fn (Container $c): SitePaths => new SitePaths(
            (array) $c->get(Config::class)->get('paths', []),
        ));

        $c->set(TrustedProxies::class, static fn (Container $c): TrustedProxies => new TrustedProxies(
            array_values((array) $c->get(Config::class)->get('trusted_proxies', [])),
        ));
        $c->set(SecurityHeaders::class, static function (Container $c): SecurityHeaders {
            $config = $c->get(Config::class);
            $policy = $config->get('security.content_security_policy');

            return new SecurityHeaders(
                is_string($policy) ? $policy : null,
                $c->get(HtmlSanitizer::class)->allowsExternalImages(),
                max(0, (int) $config->get('security.hsts', 0)),
                (bool) $config->get('security.hsts_subdomains', false),
            );
        });
        $c->set(HtmlSanitizer::class, static function () use ($root): HtmlSanitizer {
            $file = $root . '/config/html.php';
            /** @var array{elements?: array<string, list<string>>, link_schemes?: list<string>, external_images?: bool, max_length?: int} $config */
            $config = is_file($file) ? require $file : [];

            return new HtmlSanitizer($config);
        });

        $c->set(ObjectRepository::class, static fn (Container $c): ObjectRepository => new ObjectRepository(
            $c->get(Connection::class),
            $c->get(CapabilityRegistry::class),
            $c->get(BlueprintRegistry::class),
            $c->get(HtmlSanitizer::class),
        ));

        $c->set(AccessPolicy::class, static fn (): AccessPolicy => new DefaultPolicy());

        $c->set(QueryEngine::class, static fn (Container $c): QueryEngine => new QueryEngine(
            $c->get(Connection::class),
            new QueryCompiler($c->get(CapabilityRegistry::class), $c->get(BlueprintRegistry::class)),
            $c->get(ObjectRepository::class),
            $c->get(CapabilityRegistry::class),
            $c->get(AccessPolicy::class),
        ));

        $c->set(RelationLoader::class, static fn (Container $c): RelationLoader => new RelationLoader(
            $c->get(QueryEngine::class),
        ));

        $c->set(Session::class, static function (Container $c): Session {
            $config = $c->get(Config::class);

            return new Session(
                new NativeSessionStorage(
                    (string) $config->get('session.name', 'campanella_session'),
                    $config->get('session.secure', 'auto'),
                    (int) $config->get('session.idle_timeout', 7200),
                ),
                (int) $config->get('session.idle_timeout', 7200),
            );
        });

        $c->set(Csrf::class, static fn (Container $c): Csrf => new Csrf($c->get(Session::class)));

        $c->set(Flash::class, static fn (Container $c): Flash => new Flash($c->get(Session::class)));

        $c->set(AdminAccess::class, static function (Container $c): AdminAccess {
            $config = $c->get(Config::class);
            /** @var list<string> $roles */
            $roles = array_values(array_map(strval(...), (array) $config->get('admin.roles', [Actor::ADMINISTRATOR, 'editor'])));
            /** @var list<string> $systemRoles */
            $systemRoles = array_values(array_map(strval(...), (array) $config->get('admin.system_roles', [Actor::ADMINISTRATOR])));

            return new AdminAccess((string) $config->get('admin.path', '/admin'), $roles, $systemRoles);
        });

        $c->set(TemplateCache::class, static fn (): TemplateCache => new TemplateCache($root . '/var/cache/twig', Version::CAMPANELLA));
        $c->set(SystemCheck::class, static function (Container $c) use ($root): SystemCheck {
            $system = new SystemCheck(
                $c->get(Config::class),
                $c->get(Connection::class),
                $c->get(Installer::class),
                $c->get(TemplateCache::class),
                $root,
            );
            $system->add(MediaCheck::checks($c->get(ImageProcessor::class), $c->get(MediaStorage::class), $c->get(MediaService::class)));
            $system->add(SyncCheck::checks($c->get(Installer::class), $c->get(SchemaSync::class)));
            $system->add(MailCheck::checks($c->get(Mailer::class)));
            // A wrong `events` setting (since 0.1.3).
            $system->add(static function () use ($c): array {
                $problems = $c->get(EventDispatcher::class)->problems();

                return $problems === [] ? [] : [new \Campanella\System\CheckResult(
                    'admin.system.group.settings', 'events', \Campanella\System\CheckStatus::Error,
                    implode('; ', $problems), new \Campanella\I18n\Message('admin.system.events_invalid'),
                )];
            });
            $system->add(SiteCheck::checks($c->get(SiteSettings::class), $root . '/public'));
            $system->add(SecurityCheck::checks(
                $root,
                $c->get(TrustedProxies::class),
                $c->get(SecurityHeaders::class),
                static fn (): array => [
                    'document_root' => isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : null,
                    'remote_addr' => isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                ],
            ));

            return $system;
        });

        $c->set(Translator::class, static fn (Container $c): Translator => Translator::fromDirectory(
            $root . '/lang',
            (string) $c->get(Config::class)->get('locale', Translator::BASE_LOCALE),
        ));

        $c->set(Throttle::class, static fn (Container $c): Throttle => new Throttle($c->get(Connection::class)));

        $c->set(AuthService::class, static fn (Container $c): AuthService => new AuthService(
            $c->get(ObjectRepository::class),
            $c->get(QueryEngine::class),
            $c->get(Session::class),
            $c->get(Throttle::class),
            $c->get(Csrf::class),
            (array) $c->get(Config::class)->get('auth', []),
            self::guards((array) $c->get(Config::class)->get('auth.guards', [])),
        ));

        $c->set(UserService::class, static function (Container $c): UserService {
            $config = $c->get(Config::class);
            // The roles offered in the admin: the admin's and the System page's roles.
            $roles = array_values(array_unique(array_map(strval(...), array_merge(
                [Actor::ADMINISTRATOR],
                (array) $config->get('admin.roles', []),
                (array) $config->get('admin.system_roles', []),
            ))));

            return new UserService($c->get(ObjectRepository::class), $c->get(QueryEngine::class), $c->get(AccessPolicy::class), $c->get(Throttle::class), $roles, $c->get(Connection::class), $c->get(EventDispatcher::class));
        });

        // Events (since 0.1.3): the actions of the `events` setting, made on their first event.
        $c->set(EventDispatcher::class, static function (Container $c): EventDispatcher {
            $events = new EventDispatcher();
            $events->bind(
                (array) $c->get(Config::class)->get('events', []),
                static fn (string $action): Action => $action::create($c),
            );

            return $events;
        });

        // E-mail (since 0.1.3), set up in config/local.php.
        $c->set(Mailer::class, static fn (Container $c): Mailer => Mailer::fromConfig(
            (array) $c->get(Config::class)->get('mail', []),
            static fn (): Environment => $c->get(Environment::class),
            static fn (): string => $c->get(SiteSettings::class)->name(),
            $c->get(Connection::class),
        ));

        $c->set(ObjectService::class, static function (Container $c): ObjectService {
            $service = new ObjectService($c->get(ObjectRepository::class), $c->get(AccessPolicy::class), $c->get(EventDispatcher::class));
            // A deleted image takes its file with it.
            $service->addListener(new DeleteMediaFile($c->get(MediaStorage::class)));

            return $service;
        });

        $c->set(MediaStorage::class, static function (Container $c) use ($root): MediaStorage {
            $config = $c->get(Config::class);
            $directory = (string) $config->get('media.directory', 'public/media');

            return new MediaStorage(
                str_starts_with($directory, '/') ? $directory : $root . '/' . $directory,
                (string) $config->get('media.url', '/media'),
            );
        });
        $c->set(ImageProcessor::class, static function (Container $c): ImageProcessor {
            $config = $c->get(Config::class);

            return new ImageProcessor(
                (int) $config->get('media.max_bytes', 10 * 1024 * 1024),
                (int) $config->get('media.max_pixels', 25_000_000),
                (int) $config->get('media.max_dimension', 2560),
                (int) $config->get('media.quality', 85),
                null,
                (string) $config->get('media.memory_limit', '320M'),
                (bool) $config->get('media.store_unprocessed', false),
                array_values((array) $config->get('media.variants', ImageProcessor::VARIANT_WIDTHS)),
            );
        });
        $c->set(MediaService::class, static fn (Container $c): MediaService => new MediaService(
            $c->get(ObjectService::class),
            $c->get(ObjectRepository::class),
            $c->get(AccessPolicy::class),
            $c->get(ImageProcessor::class),
            $c->get(MediaStorage::class),
            'image',
            $c->get(QueryEngine::class),
            $c->get(Connection::class),
        ));
        $c->set(ResponsiveImages::class, static fn (Container $c): ResponsiveImages => new ResponsiveImages(
            $c->get(QueryEngine::class),
            $c->get(MediaStorage::class),
            (string) $c->get(Config::class)->get('media.sizes', ResponsiveImages::DEFAULT_SIZES),
        ));

        $c->set(MigrationRegistry::class, static fn (Container $c): MigrationRegistry => MigrationRegistry::fromClasses([
            ...CoreMigrations::classes(),
            ...array_values((array) $c->get(Config::class)->get('migrations', [])),
        ]));
        $c->set(Migrator::class, static fn (Container $c): Migrator => new Migrator(
            $c->get(Connection::class),
            $c->get(MigrationRegistry::class),
        ));
        $c->set(DatabaseBackup::class, static fn (Container $c): DatabaseBackup => new DatabaseBackup(
            $c->get(Connection::class),
            $root . '/var/backups',
        ));
        $c->set(Installer::class, static fn (Container $c): Installer => new Installer(
            $c->get(Connection::class),
            $c->get(CapabilityRegistry::class),
            $c->get(Migrator::class),
        ));
        $c->set(SchemaSync::class, static fn (Container $c): SchemaSync => new SchemaSync(
            $c->get(Connection::class),
            $c->get(Installer::class),
            $c->get(CapabilityRegistry::class),
            $c->get(BlueprintRegistry::class),
        ));

        $c->set(Theme::class, static fn (Container $c): Theme => Theme::fromRoot(
            $root,
            (string) $c->get(Config::class)->get('theme', ''),
        ));

        $basePath = fn (): string => $this->basePath;
        $currentRequest = fn (): Request => $this->request ?? new Request('GET', '/');
        $c->set(Environment::class, static function (Container $c) use ($root, $basePath, $currentRequest): Environment {
            $config = $c->get(Config::class);
            $debug = (bool) $config->get('debug', false);

            // A folder per version (TemplateCache). If it is not writable (a common permission
            // problem on web hosts and in Docker), Twig keeps running without a cache: slower, but it works.
            $templates = $c->get(TemplateCache::class);
            $cache = $templates->twigCache();
            if ($cache === false) {
                error_log("Campanella: the {$templates->directory()} directory is not writable, the template cache is disabled.");
            }

            // The active theme's templates take precedence over the core ones; the core
            // templates are also reachable as @core/… (e.g. to extend them from a theme).
            $theme = $c->get(Theme::class);
            $loader = new FilesystemLoader();
            if ($theme->templateDir !== null) {
                $loader->addPath($theme->templateDir);
            }
            $loader->addPath($root . '/templates');
            $loader->addPath($root . '/templates', 'core');

            $twig = new Environment($loader, [
                'cache' => $cache,
                'debug' => $debug,
                // Always check whether a template changed since it was compiled (a cheap
                // file time check), so an upgrade never keeps serving old compiled templates.
                'auto_reload' => true,
                'strict_variables' => $debug,
                'autoescape' => 'html',
            ]);
            // Every template is sandboxed: on objects only the safe reads (TemplatePolicy).
            $twig->addExtension(new SandboxExtension(new TemplatePolicy(), true));
            $core = $twig->getExtension(CoreExtension::class);
            $core->setTimezone((string) $config->get('timezone', 'UTC'));
            $core->setDateFormat('Y. m. d. H:i');

            $twig->addExtension(new CampanellaTwigExtension(
                static fn (): Presentation => $c->get(Presentation::class),
                $basePath,
                ['site' => $c->get(SiteValues::class), 'campanella_version' => Version::CAMPANELLA],
                static fn () => $c->get(AuthService::class)->currentUser($currentRequest()),
                static fn (): string => $c->get(Csrf::class)->token($currentRequest()),
                static fn (): Translator => $c->get(Translator::class),
                $theme,
                $c->get(AdminAccess::class),
                static fn (): Actor => $c->get(AuthService::class)->currentActor($currentRequest()),
                static fn (): Flash => $c->get(Flash::class),
                static fn (string $key, int $levels): ?array => $c->get(MenuBuilder::class)->build(
                    $key,
                    $c->get(AuthService::class)->currentActor($currentRequest()),
                    $currentRequest()->path,
                    $levels,
                    $basePath(),
                ),
                static fn (): ResponsiveImages => $c->get(ResponsiveImages::class),
                $c->get(SitePaths::class),
            ));

            return $twig;
        });

        $c->set(MenuBuilder::class, static fn (Container $c): MenuBuilder => new MenuBuilder(
            $c->get(QueryEngine::class),
            $c->get(RelationLoader::class),
        ));

        $c->set(Presentation::class, static fn (Container $c): Presentation => new Presentation(
            $c->get(Environment::class),
        ));

        $c->set(Router::class, static function (Container $c) use ($root): Router {
            $router = new Router(require $root . '/config/routes.php');
            $router->prefix($c->get(AdminAccess::class)->path(), 'admin');
            $router->add($c->get(AdminAccess::class)->path('upgrade'), 'upgrade');
            $router->add(InstallController::PATH, 'install');
            // Logging in and out, at the configured paths (since 0.1.4).
            $paths = $c->get(SitePaths::class);
            $router->add($paths->get('login'), 'auth', ['action' => 'login']);
            $router->add($paths->get('logout'), 'auth', ['action' => 'logout']);
            // For search engines (since 0.1.1).
            $router->add('/robots.txt', 'site', ['action' => 'robots']);
            $router->add('/sitemap.xml', 'site', ['action' => 'sitemap']);

            return $router;
        });

        $c->set('controller.object', static fn (Container $c): Controller => new ObjectController(
            $c->get(QueryEngine::class),
            $c->get(Presentation::class),
            $c->get(BlueprintRegistry::class),
            $c->get(RelationLoader::class),
            $c->get(MetaBuilder::class),
        ));

        $c->set('controller.admin', static fn (Container $c): Controller => new AdminController(
            $c->get(AdminAccess::class),
            $c->get(QueryEngine::class),
            $c->get(BlueprintRegistry::class),
            $c->get(Presentation::class),
            $c->get(RelationLoader::class),
            $c->get(ObjectRepository::class),
            $c->get(ObjectService::class),
            $c->get(AccessPolicy::class),
            new ObjectForm(
                $c->get(QueryEngine::class),
                $c->get(Translator::class),
                (string) $c->get(Config::class)->get('timezone', 'UTC'),
                $c->get(HtmlSanitizer::class),
            ),
            $c->get(Csrf::class),
            $c->get(Flash::class),
            $c->get(Translator::class),
            $c->get(Router::class),
            $c->get(SystemCheck::class),
            $c->get(TemplateCache::class),
            $c->get(MediaService::class),
            new SiblingOrder($c->get(Connection::class), $c->get(BlueprintRegistry::class)),
            new UserPages($c->get(UserService::class), $c->get(AuthService::class), $c->get(Csrf::class), $c->get(Flash::class), $c->get(AdminAccess::class)),
            $c->get(UserService::class),
            new StructurePages($c->get(BlueprintRegistry::class), $c->get(CapabilityRegistry::class), $c->get(QueryEngine::class), $c->get(AdminAccess::class)),
            new SettingsPage($c->get(SiteSettings::class), $c->get(QueryEngine::class), $c->get(MediaStorage::class), $c->get(Csrf::class), $c->get(Flash::class), $c->get(AdminAccess::class)),
            new MediaUsage($c->get(Connection::class), $c->get(QueryEngine::class), $c->get(SiteSettings::class)),
            new MailPages($c->get(Mailer::class), $c->get(ObjectRepository::class), $c->get(Throttle::class), $c->get(Csrf::class), $c->get(Flash::class), $c->get(AdminAccess::class)),
            $c->get(SitePaths::class),
        ));

        $c->set('controller.upgrade', static fn (Container $c): Controller => new UpgradeController(
            $c->get(Installer::class),
            $c->get(Migrator::class),
            $c->get(SchemaSync::class),
            $c->get(DatabaseBackup::class),
            $c->get(AdminAccess::class),
            $c->get(Csrf::class),
            $c->get(Throttle::class),
            $c->get(Presentation::class),
            UpgradeController::usableKey($c->get(Config::class)->get('upgrade.key')),
            $c->get(SitePaths::class),
        ));

        $c->set('controller.install', static fn (Container $c): Controller => new InstallController(
            $c->get(Installer::class),
            $c->get(Connection::class),
            $c->get(ObjectRepository::class),
            $c->get(AuthService::class),
            $c->get(Csrf::class),
            $c->get(Flash::class),
            new FileThrottle($root . '/var/cache/install-throttle.json'),
            $c->get(Presentation::class),
            $c->get(AdminAccess::class),
            InstallController::usableKey($c->get(Config::class)->get('install.key')),
            [
                'var/cache' => $root . '/var/cache',
                'var/backups' => $root . '/var/backups',
                (string) $c->get(Config::class)->get('media.directory', 'public/media') => $c->get(MediaStorage::class)->directory(),
            ],
            static function () use ($c): void {
                $stream = fopen('php://memory', 'w+');
                (new SeedCommand())->run($c, [], new Output($stream ?: STDOUT, $stream ?: STDERR));
            },
            $c->get(SiteSettings::class),
        ));

        $c->set('controller.site', static function (Container $c): Controller {
            $router = $c->get(Router::class);
            $admin = $c->get(AdminAccess::class);

            return new SiteController(
                $c->get(SiteSettings::class),
                $c->get(QueryEngine::class),
                $router->paths('query'),
                // The admin only at its default path: a path of its own is not revealed here
                // (the admin pages send noindex themselves).
                [...($admin->path() === '/admin' ? ['/admin/'] : []), ...$router->paths('auth'), InstallController::PATH],
            );
        });

        $c->set('controller.auth', static fn (Container $c): Controller => new AuthController(
            $c->get(AuthService::class),
            $c->get(Csrf::class),
            $c->get(Presentation::class),
            $c->get(Translator::class),
        ));

        $c->set('controller.query', static fn (Container $c): Controller => new QueryController(
            $c->get(QueryEngine::class),
            $c->get(Presentation::class),
            require $root . '/config/queries.php',
            $c->get(RelationLoader::class),
            $c->get(MetaBuilder::class),
        ));

        return $c;
    }

    /**
     * @param array<mixed> $classes
     * @return list<LoginGuard>
     */
    private static function guards(array $classes): array
    {
        $guards = [];
        foreach ($classes as $class) {
            $guard = is_string($class) && class_exists($class) ? new $class() : null;
            if (!$guard instanceof LoginGuard) {
                throw new \LogicException('auth.guards may only contain LoginGuard classes: ' . var_export($class, true));
            }
            $guards[] = $guard;
        }

        return $guards;
    }

    private function failure(\Throwable $e): Response
    {
        $debug = false;
        try {
            $debug = (bool) $this->container()->get(Config::class)->get('debug', false);
            if ($e instanceof \PDOException) {
                $installer = $this->container()->get(Installer::class);
                if (!$installer->isInstalled()) {
                    return $this->errorResponse(503, 'error.not_installed');
                }
                // A new table, column or capability of the code that is not in the database yet.
                if ($installer->needsUpgrade() || $this->container()->get(SchemaSync::class)->plan()->hasWork()) {
                    return $this->errorResponse(503, 'error.needs_upgrade');
                }
            }
        } catch (\Throwable) {
            // Error handling itself must not throw.
        }
        error_log((string) $e);

        return $this->errorResponse(500, $debug ? get_class($e) . ': ' . $e->getMessage() : 'error.internal');
    }

    /**
     * @param string $message A message key (see lang/) or a ready-made text.
     * @param array<string, string|int|float> $params
     */
    private function errorResponse(int $status, string $message, array $params = []): Response
    {
        try {
            $message = $this->container()->get(Translator::class)->translate($message, $params);
        } catch (\Throwable) {
            // Without a working translator, the key is shown.
        }
        try {
            $html = $this->container()->get(Presentation::class)->render('page/error.html.twig', [
                'status' => $status,
                'message' => $message,
                'title' => (string) $status,
            ]);
        } catch (\Throwable) {
            $html = sprintf(
                '<!doctype html><meta charset="utf-8"><title>%d</title><h1>%d</h1><p>%s</p>',
                $status,
                $status,
                htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            );
        }

        // An error page is never indexed (since 0.1.1).
        return Response::html($html, $status)->withHeader('X-Robots-Tag', 'noindex');
    }
}

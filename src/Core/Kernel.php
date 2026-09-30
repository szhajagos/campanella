<?php

declare(strict_types=1);

namespace Campanella\Core;

use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\DefaultPolicy;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Auth\AuthService;
use Campanella\Auth\LoginGuard;
use Campanella\Controller\AuthController;
use Campanella\Controller\Controller;
use Campanella\Controller\ObjectController;
use Campanella\Controller\QueryController;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Http\HttpException;
use Campanella\Http\NativeSessionStorage;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\Router;
use Campanella\Http\Session;
use Campanella\I18n\Translator;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ObjectRepository;
use Campanella\Query\QueryCompiler;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;
use Campanella\Service\ObjectService;
use Campanella\View\CampanellaTwigExtension;
use Campanella\View\Presentation;
use Twig\Environment;
use Twig\Extension\CoreExtension;
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
        // The Twig extension reads the URL prefix from here per request, so the
        // container (and the services overridden in it) persists between requests.
        $this->basePath = $request->basePath;
        $this->request = $request;

        try {
            $container = $this->container();
            $route = $container->get(Router::class)->match($request);
            /** @var Controller $controller */
            $controller = $container->get('controller.' . $route->handler);
            $actor = $container->get(AuthService::class)->currentActor($request);

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

        $c->set(ObjectRepository::class, static fn (Container $c): ObjectRepository => new ObjectRepository(
            $c->get(Connection::class),
            $c->get(CapabilityRegistry::class),
            $c->get(BlueprintRegistry::class),
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

        $c->set(ObjectService::class, static fn (Container $c): ObjectService => new ObjectService(
            $c->get(ObjectRepository::class),
            $c->get(AccessPolicy::class),
        ));

        $c->set(Installer::class, static fn (Container $c): Installer => new Installer(
            $c->get(Connection::class),
            $c->get(CapabilityRegistry::class),
        ));

        $basePath = fn (): string => $this->basePath;
        $currentRequest = fn (): Request => $this->request ?? new Request('GET', '/');
        $c->set(Environment::class, static function (Container $c) use ($root, $basePath, $currentRequest): Environment {
            $config = $c->get(Config::class);
            $debug = (bool) $config->get('debug', false);

            // If the cache directory is not writable (a common permission problem on web
            // hosts and in Docker), Twig keeps running without a cache: slower, but it works.
            $cacheDir = $root . '/var/cache/twig';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            $cache = is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : false;
            if ($cache === false) {
                error_log("Campanella: the {$cacheDir} directory is not writable, the template cache is disabled.");
            }

            $twig = new Environment(new FilesystemLoader($root . '/templates'), [
                'cache' => $cache,
                'debug' => $debug,
                'auto_reload' => $debug,
                'strict_variables' => $debug,
                'autoescape' => 'html',
            ]);
            $core = $twig->getExtension(CoreExtension::class);
            $core->setTimezone((string) $config->get('timezone', 'UTC'));
            $core->setDateFormat('Y. m. d. H:i');

            $twig->addExtension(new CampanellaTwigExtension(
                static fn (): Presentation => $c->get(Presentation::class),
                $basePath,
                ['site' => $config->get('site', []), 'campanella_version' => Version::CAMPANELLA],
                static fn () => $c->get(AuthService::class)->currentUser($currentRequest()),
                static fn (): string => $c->get(Csrf::class)->token($currentRequest()),
                static fn (): Translator => $c->get(Translator::class),
            ));

            return $twig;
        });

        $c->set(Presentation::class, static fn (Container $c): Presentation => new Presentation(
            $c->get(Environment::class),
        ));

        $c->set(Router::class, static fn (): Router => new Router(require $root . '/config/routes.php'));

        $c->set('controller.object', static fn (Container $c): Controller => new ObjectController(
            $c->get(QueryEngine::class),
            $c->get(Presentation::class),
            $c->get(BlueprintRegistry::class),
            $c->get(RelationLoader::class),
        ));

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
                if ($installer->needsUpgrade()) {
                    return $this->errorResponse(503, 'error.needs_upgrade');
                }
            }
        } catch (\Throwable) {
            // Error handling itself must not throw.
        }
        error_log((string) $e);

        return $this->errorResponse(500, $debug ? get_class($e) . ': ' . $e->getMessage() : 'error.internal');
    }

    /** @param string $message A message key (see lang/) or a ready-made text. */
    private function errorResponse(int $status, string $message): Response
    {
        try {
            $message = $this->container()->get(Translator::class)->translate($message);
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

        return Response::html($html, $status);
    }
}

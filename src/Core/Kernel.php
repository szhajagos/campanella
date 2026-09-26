<?php

declare(strict_types=1);

namespace Campanella\Core;

use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\DefaultPolicy;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Controller\Controller;
use Campanella\Controller\ObjectController;
use Campanella\Controller\QueryController;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\Router;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ObjectRepository;
use Campanella\Query\QueryCompiler;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use Campanella\View\CampanellaTwigExtension;
use Campanella\View\Presentation;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;

/**
 * A rendszer összerakása és egy kérés kiszolgálása.
 *
 *   HTTP kérés → Router → Controller → Service / Query Engine (Model)
 *              → Presentation + Twig (View) → HTTP válasz
 */
final class Kernel
{
    private ?Container $container = null;
    private string $basePath = '';

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
        $this->basePath = $request->basePath;
        $this->container = null; // az URL-előtag a Twig-kiterjesztésbe kerül

        try {
            $container = $this->container();
            $route = $container->get(Router::class)->match($request);
            /** @var Controller $controller */
            $controller = $container->get('controller.' . $route->handler);

            // A 0.0.1-ben még nincs bejelentkezés: minden látogató anonymous.
            return $controller->handle($request, $route, Actor::anonymous());
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
            new QueryCompiler($c->get(CapabilityRegistry::class)),
            $c->get(ObjectRepository::class),
            $c->get(CapabilityRegistry::class),
            $c->get(AccessPolicy::class),
        ));

        $c->set(ObjectService::class, static fn (Container $c): ObjectService => new ObjectService(
            $c->get(ObjectRepository::class),
            $c->get(AccessPolicy::class),
        ));

        $c->set(Installer::class, static fn (Container $c): Installer => new Installer(
            $c->get(Connection::class),
            $c->get(CapabilityRegistry::class),
        ));

        $basePath = $this->basePath;
        $c->set(Environment::class, static function (Container $c) use ($root, $basePath): Environment {
            $config = $c->get(Config::class);
            $debug = (bool) $config->get('debug', false);

            // Ha a gyorsítótár mappája nem írható (gyakori jogosultsági gond tárhelyen
            // és Dockerben), a Twig gyorsítótár nélkül fut tovább: lassabb, de működik.
            $cacheDir = $root . '/var/cache/twig';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            $cache = is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : false;
            if ($cache === false) {
                error_log("Campanella: a {$cacheDir} mappa nem írható, a sablon-gyorsítótár ki van kapcsolva.");
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
        ));

        $c->set('controller.query', static fn (Container $c): Controller => new QueryController(
            $c->get(QueryEngine::class),
            $c->get(Presentation::class),
            require $root . '/config/queries.php',
        ));

        return $c;
    }

    private function failure(\Throwable $e): Response
    {
        $debug = false;
        try {
            $debug = (bool) $this->container()->get(Config::class)->get('debug', false);
            if ($e instanceof \PDOException && !$this->container()->get(Installer::class)->isInstalled()) {
                return $this->errorResponse(
                    503,
                    'A Campanella még nincs telepítve. Futtasd: php bin/campanella install',
                );
            }
        } catch (\Throwable) {
            // A hibakezelés maga ne dobjon hibát.
        }
        error_log((string) $e);

        return $this->errorResponse(500, $debug ? get_class($e) . ': ' . $e->getMessage() : 'Belső hiba történt.');
    }

    private function errorResponse(int $status, string $message): Response
    {
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

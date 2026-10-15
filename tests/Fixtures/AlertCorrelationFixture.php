<?php

namespace LibreNMS\Tests\Fixtures;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AlertCorrelationFixture
{
    private readonly Container $previousContainer;
    private readonly ?\Illuminate\Contracts\Foundation\Application $previousFacade;
    public Application $app;
    public Dispatcher $events;
    public DatabaseManager $database;

    /** @param array<string, array<string, mixed>> $connections */
    public function __construct(array $connections = [])
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->app = new Application(dirname(__DIR__, 2));
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
        $this->events = new Dispatcher($this->app);
        $this->app->instance('events', $this->events);
        $this->app->instance('config', new Repository(['database' => [
            'default' => 'correlation', 'connections' => $connections,
        ]]));
        $this->database = new DatabaseManager($this->app, new ConnectionFactory($this->app));
        $this->app->instance('db', $this->database);
        $views = new ViewFactory(new EngineResolver, new FileViewFinder(new Filesystem, []), $this->events);
        $redirect = new Redirector(new UrlGenerator(new RouteCollection, Request::create('/')));
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory($views, $redirect));
        if (! class_exists('DB', false)) {
            class_alias(\Illuminate\Support\Facades\DB::class, 'DB');
        }
        require_once dirname(__DIR__, 2) . '/includes/html/api_functions.inc.php';
    }

    public function close(): void
    {
        foreach (array_keys($this->database->getConnections()) as $name) {
            $this->database->purge($name);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
    }

    /** @param array<string, mixed> $query */
    public static function request(array $query, ?int $id = null): Request
    {
        $request = Request::create('/api/v0/alerts' . ($id === null ? '' : '/' . $id), 'GET', $query);
        if ($id !== null) {
            $route = (new Route('GET', 'api/v0/alerts/{id}', static fn () => null))->bind($request);
            $request->setRouteResolver(static fn () => $route);
        }

        return $request;
    }

    public static function profile(): JsonResponse|StreamedResponse
    {
        return list_alerts(self::request(['profile' => 'msp-correlation-v1', 'state' => '0,1']));
    }

    /** @return array<string, mixed> */
    public static function body(JsonResponse|StreamedResponse $response): array
    {
        if ($response instanceof JsonResponse) {
            return $response->getData(true);
        }
        ob_start();
        try {
            $response->sendContent();
            $text = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (strlen($text) !== (int) $response->headers->get('Content-Length')) {
            throw new RuntimeException('Incomplete metadata response');
        }

        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * This handoff is produced by the caller that owns the disposable database/container.
     *
     * @return array<string, mixed>
     */
    public static function creatorConnection(string $path): array
    {
        $stat = @lstat($path);
        if (! str_starts_with($path, '/') || $stat === false || is_link($path)
            || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || $stat['uid'] !== getmyuid() || $stat['nlink'] !== 1 || $stat['size'] > 4096) {
            throw new RuntimeException('Invalid disposable database handoff');
        }
        $value = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (($value['kind'] ?? null) !== 'CREATOR_OWNED_DISPOSABLE_MARIADB'
            || ($value['host'] ?? null) !== 'librenms-correlation-fixture'
            || ($value['database'] ?? null) !== 'librenms_correlation_test'
            || ! preg_match('/^[a-f0-9]{64}$/D', $value['containerId'] ?? '')
            || ! preg_match('/^[a-f0-9]{64}$/D', $value['networkId'] ?? '')) {
            throw new RuntimeException('Invalid disposable database handoff');
        }

        // Never inherit DB_URL, DB_HOST, DB_TEST_* or a production connection configuration.
        return ['driver' => 'mysql', 'host' => $value['host'], 'port' => 3306,
            'database' => $value['database'], 'username' => 'fixture', 'password' => 'fixture-only',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
    }
}

<?php

declare(strict_types=1);

use Hilos\Core\Daemon\ClientSocketDetacher;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\StandGateway\StandGatewayTlsServer;

// The stand's gateway for every non-mail channel (HIL-492, HIL-653), served over TLS by the
// framework's own server (HIL-921). The framework arrives as the stack's read-only volume
// and is loaded by hand: the gateway uses its socket, HTTP and routing classes and nothing
// that needs a package, so there is no composer here and no vendor directory to keep current.

/** Where the stack mounts the framework's backend. */
const FRAMEWORK_BACKEND = '/hilos/framework/backend/';

/** Where the image carries the gateway's own classes. */
const GATEWAY_SOURCES = __DIR__ . '/../src/';

/** Address the gateway listens on inside its container. */
const LISTEN_HOST = '0.0.0.0';

/** Port every stack's endpoint URLs name. */
const LISTEN_PORT = 18000;

/** Certificate and private key the gateway presents; the callers trust tls/ca.pem. */
const CERTIFICATE_FILE = __DIR__ . '/../tls/server.pem';

spl_autoload_register(static function (string $class): void {
    // The gateway's prefix goes first: it is inside the framework's own.
    $roots = ['Hilos\\StandGateway\\' => GATEWAY_SOURCES, 'Hilos\\' => FRAMEWORK_BACKEND];

    foreach ($roots as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

// Env without a database or a topology: the client and the router read their buffer size,
// keep-alive policy and session cookie name, and every one of them has a catalog default.
Hilos::initEnv(dirname(__DIR__));

$loop = new EventLoop();
$server = new StandGatewayTlsServer(LISTEN_HOST, LISTEN_PORT, CERTIFICATE_FILE);

// A client leaving the server comes off the watch before its socket closes, as it does in
// the daemon: a watch left on a closed descriptor keeps firing, and a non-blocking turn of
// the loop never returns while an event stays active.
$server->setClientSocketDetacher(new readonly class ($loop) implements ClientSocketDetacher {
    public function __construct(private EventLoop $loop)
    {
    }

    public function detachClientSocket(ClientInterface $client): void
    {
        $socket = $client->getSocket();
        if ($socket !== null) {
            $this->loop->unregister($socket);
        }
    }
});

$server->prepareTlsContexts();
if (!$server->start()) {
    error_log('stand gateway could not listen on port ' . LISTEN_PORT);
    exit(1);
}

$server->setClientSocketAttacher(static function (ClientInterface $client) use ($loop, $server): void {
    $client->attachEventLoop($loop, static function () use ($server, $client): void {
        try {
            $client->read();
            $client->onTick();
            $client->write();
            if ($client->shouldClose()) {
                $server->dropClient($client);
            }
        } catch (Throwable $exception) {
            error_log('stand gateway dropped a connection: ' . $exception->getMessage());
            $server->dropClient($client);
        }
    });
});

$loop->registerRead($server->getSocket(), static function () use ($server): void {
    $server->acceptConnection();
});

// PID 1 of its container: without a handler SIGTERM is ignored, and every stack teardown
// would wait out the stop timeout before the kill. The native base owns these signals so
// they wake an idle runOnce() as well as a busy gateway.
$running = true;
foreach ([SIGTERM, SIGINT] as $stopSignal) {
    $loop->registerSignal($stopSignal, static function () use (&$running): void {
        $running = false;
    });
}

try {
    while ($running) {
        $loop->runOnce();
    }
} finally {
    $loop->unregister($server->getSocket());
    foreach ([SIGTERM, SIGINT] as $stopSignal) {
        $loop->unregisterSignal($stopSignal);
    }
    try {
        $server->stop();
    } finally {
        $loop->cleanup();
    }
}

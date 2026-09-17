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

/**
 * Pause between two turns of the loop, in microseconds.
 *
 * A turn does not wait for an event: bytes OpenSSL has already decrypted into its own
 * buffer raise no readability on the socket, and only the server's tick, which reads every
 * client, picks them up.
 */
const LOOP_PAUSE_US = 10000;

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

if (!$server->start()) {
    error_log('stand gateway could not listen on port ' . LISTEN_PORT);
    exit(1);
}

$loop->registerRead($server->getSocket(), static function () use ($loop, $server): void {
    $client = $server->acceptConnection();
    if ($client === null) {
        return;
    }

    $loop->registerRead($client->getSocket(), static function () use ($server, $client): void {
        try {
            $client->read();
            $client->write();

            if ($client->shouldClose()) {
                $server->dropClient($client);
            }
        } catch (Throwable $exception) {
            // The loop swallows what a callback throws, so a client that failed here would
            // stay on the watch; it is dropped instead, as the daemon drops it.
            error_log('stand gateway dropped a connection: ' . $exception->getMessage());
            $server->dropClient($client);
        }
    });
});

// PID 1 of its container: without a handler SIGTERM is ignored, and every stack teardown
// would wait out the stop timeout before the kill.
$running = true;
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT] as $stopSignal) {
    pcntl_signal($stopSignal, static function () use (&$running): void {
        $running = false;
    });
}

while ($running) {
    $loop->tick();
    $server->onTick();
    usleep(LOOP_PAUSE_US);
}

$server->stop();

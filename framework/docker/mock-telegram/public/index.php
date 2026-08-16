<?php

declare(strict_types=1);

use Hilos\MockTelegram\GatewayServer;

// The stand's Telegram Gateway (HIL-492). One entry point, because the built-in
// server runs in router mode and every request re-enters here; the routes - provider
// and test side alike - are declared in GatewayServer.
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/GatewayServer.php';

new GatewayServer()->run();

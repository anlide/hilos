<?php

declare(strict_types=1);

use Hilos\StandGateway\StandGatewayServer;

// The stand's gateway for every non-mail channel (HIL-492, HIL-653). One entry
// point, because the built-in server runs in router mode and every request
// re-enters here; the routes - provider and test side alike - are declared by
// StandGatewayServer and the per-channel route classes it assembles.
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/MailForwardException.php';
require __DIR__ . '/../src/MailForwarder.php';
require __DIR__ . '/../src/TelegramRoutes.php';
require __DIR__ . '/../src/SmsRoutes.php';
require __DIR__ . '/../src/StandGatewayServer.php';

new StandGatewayServer()->run();

<?php

require __DIR__ . '/../../vendor/autoload.php';

use Hilos\Tests\Daemon\Master1;
use Hilos\Daemon\Server\Internal;

$master = new Master1();
$master->registerServer(new Internal(8207, \Hilos\Tests\Daemon\Client\Internal::class));
$master->run();
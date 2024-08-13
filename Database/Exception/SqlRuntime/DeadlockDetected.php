<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\SqlRuntime;
use Throwable;

class DeadlockDetected extends SqlRuntime implements Throwable
{
}

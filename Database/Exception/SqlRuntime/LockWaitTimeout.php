<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\SqlRuntime;
use Throwable;

class LockWaitTimeout extends SqlRuntime implements Throwable
{
}

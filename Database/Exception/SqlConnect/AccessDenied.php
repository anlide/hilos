<?php

namespace Hilos\Database\Exception\SqlConnect;

use Hilos\Database\Exception\SqlConnection;
use Throwable;

class AccessDenied extends SqlConnection implements Throwable
{
}

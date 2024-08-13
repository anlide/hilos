<?php

namespace Hilos\Database\Exception\SqlConnect;

use Hilos\Database\Exception\SqlConnection;
use Throwable;

class SslConnectionError extends SqlConnection implements Throwable
{
}

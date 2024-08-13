<?php

namespace Hilos\Database\Exception\SqlConnect;

use Hilos\Database\Exception\SqlConnection;
use Throwable;

class TooManyConnections extends SqlConnection implements Throwable
{
}

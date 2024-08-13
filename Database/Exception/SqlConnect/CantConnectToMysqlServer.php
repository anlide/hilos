<?php

namespace Hilos\Database\Exception\SqlConnect;

use Hilos\Database\Exception\SqlConnection;
use Throwable;

class CantConnectToMysqlServer extends SqlConnection implements Throwable
{
}

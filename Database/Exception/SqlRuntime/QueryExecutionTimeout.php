<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\SqlRuntime;
use Throwable;

class QueryExecutionTimeout extends SqlRuntime implements Throwable
{
}

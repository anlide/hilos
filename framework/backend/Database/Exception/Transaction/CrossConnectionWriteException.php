<?php

declare(strict_types=1);

namespace Hilos\Database\Exception\Transaction;

use Hilos\Database\DatabaseException;

/** An ORM write cannot participate in another connection's application transaction. */
class CrossConnectionWriteException extends DatabaseException
{
}

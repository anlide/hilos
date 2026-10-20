<?php

declare(strict_types=1);

namespace Hilos\Database;

/** Vocabulary of the test-only, process-local ORM forget command. */
final class OrmTestConstants
{
    public const string FIELD_PROCESS = 'process';
    public const string FIELD_WORKER_ID = 'workerId';
    public const string PROCESS_DAEMON = 'daemon';
    public const string PROCESS_WORKER = 'worker';

    public const string INVALID_TARGET = 'Invalid ORM forget target';
    public const string NO_CONTEXT = 'This process has no database context';
    public const string OPEN_TRANSACTIONS = 'ORM forget requires all transactions to be closed';
}

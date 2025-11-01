<?php

declare(strict_types=1);

namespace Hilos\Utils\Constants;

/**
 * WorkerConstants - Worker-related constants
 *
 * Defines command line argument names for worker processes.
 * Formats are derived in ArgumentHelper.
 */
class WorkerConstants
{
    /** @var string Worker ID command line argument name */
    public const string WORKER_ID_ARG = 'worker-id';

    /** @var string Monopolistic worker flag name */
    public const string MONOPOLISTIC_ARG = 'monopolistic';
}


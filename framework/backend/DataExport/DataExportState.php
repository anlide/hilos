<?php

declare(strict_types=1);

namespace Hilos\DataExport;

/** Persisted states of a person's single data copy. */
final class DataExportState
{
    public const string PREPARING = 'preparing';
    public const string READY = 'ready';
    public const string FAILED = 'failed';
}

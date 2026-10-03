<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

/** Persisted states of an administrator's single file of acceptance records. */
final class LegalAcceptancesExportState
{
    public const string PREPARING = 'preparing';
    public const string READY = 'ready';
    public const string FAILED = 'failed';
}

<?php

declare(strict_types=1);

namespace Hilos\DataExport;

/** Refusals and completion notices for personal-data copies. */
final class DataExportMessages
{
    public const string NOBODY = 'Sign in to download your data';
    public const string READY_TITLE = 'Your copy of your data is ready';
    public const string READY_BODY = 'It is kept for %d days. Open Profile › Your data to download it.';
    public const string FAILED_TITLE = 'We could not prepare your copy of your data';
    public const string FAILED_BODY = 'Open Profile › Your data to try again.';
}

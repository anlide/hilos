<?php

declare(strict_types=1);

namespace Hilos\DataExport;

/** Optional framework notifications: unregistered types honor the person's channel preferences. */
final class DataExportNotificationType
{
    public const string READY = 'data_export.ready';
    public const string FAILED = 'data_export.failed';
    public const string DATA_URL = 'url';
    public const string SECTION_PATH = '/profile/data';
}

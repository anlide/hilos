<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Read-only analytics probe on the non-production command channel. */
final class AnalyticsCommandConstants
{
    public const string TEST_BROWSER_SESSION = 'test:analytics:browser-session';
    public const string FIELD_USER_ID = 'userId';
    public const string FIELD_BROWSER_SESSION_ID = 'browserSessionId';
    public const string FIELD_ACTION_NAMES = 'actionNames';
}

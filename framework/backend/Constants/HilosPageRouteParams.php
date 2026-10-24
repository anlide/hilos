<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosPageRouteParams - Dynamic segment names for Hilos admin routes.
 *
 * Keys match Vue Router params and WebSocket `page_subscribe.params`.
 * Keep in sync with the frontend page-route-param constants.
 */
final class HilosPageRouteParams
{
    /**
     * Route param for {@see HilosPageConstants::HILOS_USER} (`/hilos/user/{userId}`).
     */
    public const string HILOS_USER_USER_ID = 'userId';

    /**
     * Route param for {@see HilosPageConstants::HILOS_SIL_USER_HISTORY} (`/hilos/sil/users/{userId}`).
     */
    public const string HILOS_SIL_USER_HISTORY_USER_ID = self::HILOS_USER_USER_ID;

    /**
     * Route param for {@see HilosPageConstants::HILOS_GUARDIAN_AGENT} (`/hilos/guardian/{agentId}`).
     */
    public const string HILOS_GUARDIAN_AGENT_AGENT_ID = AgentConstants::FIELD_AGENT_ID;

    /** Route param for the Daemon section's node child pages. */
    public const string HILOS_DAEMON_NODE_ID = 'nodeId';

    /** Route param for one analytics browser session. */
    public const string HILOS_ANALYTICS_BROWSER_SESSION_ID = 'browserSessionId';

    /** Reader context for the analytics overview, carried only in subscription params. */
    public const string HILOS_ANALYTICS_TIME_ZONE = 'timeZone';
    public const string HILOS_ANALYTICS_WEEK_START_TS = 'weekStartTs';
    public const string HILOS_ANALYTICS_DAY_START_TS = 'dayStartTs';
    public const string HILOS_ANALYTICS_HOUR_START_TS = 'hourStartTs';

    /**
     * Route param for {@see HilosPageConstants::HILOS_ANALYTICS_PERSON} (`/hilos/analytics/people/{userId}`).
     */
    public const string HILOS_ANALYTICS_PERSON_USER_ID = self::HILOS_USER_USER_ID;

    /** Route param for one live table of the change log. */
    public const string HILOS_CHANGE_LOG_TABLE_ID = 'tableId';

    /** Route param for language detail pages. */
    public const string HILOS_I18N_LANGUAGE_CODE = 'languageCode';

    /** Route param for country detail pages. */
    public const string HILOS_I18N_COUNTRY_CODE = 'countryCode';

    /** Legal document and revision route parameters. */
    public const string HILOS_LEGAL_DOCUMENT_KEY = 'documentKey';
    public const string HILOS_LEGAL_REVISION_ID = 'revisionId';
}

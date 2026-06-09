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
     * Route param for {@see HilosPageConstants::HILOS_USER} (`/hilos/users/{userId}`).
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
}

<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Constants;

/**
 * Cookie name constants.
 *
 * Project-specific cookie names. The session token cookie name is owned by the
 * project, not the framework, so every Hilos project can choose its own.
 */
final class CookieNames
{
    /** @var string Session token cookie name (WebSocket authentication) */
    public const string SESSION_TOKEN = 'hilos_session_token';
}

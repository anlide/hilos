<?php

declare(strict_types=1);

namespace Demo\Chat;

/**
 * Main app facade for data access.
 *
 * Usage:
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 * - Hilos::$table?->users
 */
final class Hilos extends Database\DbChat
{
    public const string connections = 'connections';
    public const string user = 'user';
}

<?php

namespace Demo\Chat\Database;

/**
 * Main app facade for data access.
 *
 * Usage:
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 * - Hilos::$table?->users
 */
final class Hilos extends DbChat
{
    public const string connections = 'connections';
    public const string user = 'user';
}

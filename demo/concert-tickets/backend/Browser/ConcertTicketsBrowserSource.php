<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Browser;

use Demo\ConcertTickets\Database\ConcertTicketsDbContext;
use Demo\ConcertTickets\Runtime\View\Context\ConcertTicketsRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class ConcertTicketsBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ConcertTicketsDbContext::users,
    ];

    public const array DB_SESSIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => ConcertTicketsDbContext::sessions,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => ConcertTicketsRtContext::connections,
    ];
}

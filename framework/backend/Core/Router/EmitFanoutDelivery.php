<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * How an emit fanout item should be delivered to WebSocket clients.
 */
enum EmitFanoutDelivery: string
{
    /** Broadcast JSON to every subscribed client except optional excludeAcceptKey */
    case AllExcept = 'all_except';

    /** Send JSON to a single acceptKey */
    case Single = 'single';
}

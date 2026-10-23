<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Current component verdict; Unknown never asserts observed absence or health. */
enum StatusComponentState: string
{
    case OPERATIONAL = 'operational';
    case CHECKING = 'checking';
    case PARTIAL_OUTAGE = 'partial_outage';
    case MAJOR_OUTAGE = 'major_outage';
    case MAINTENANCE = 'maintenance';
    case UNKNOWN = 'unknown';
}

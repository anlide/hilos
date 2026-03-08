<?php

declare(strict_types=1);

namespace Hilos\Guardian\Enums;

enum Severity: string
{
    case INFO = 'info';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}

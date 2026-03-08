<?php

declare(strict_types=1);

namespace Hilos\Guardian\Enums;

enum FindingType: string
{
    case LOGS = 'logs';
    case SECURITY = 'security';
    case BUSINESS = 'business';
    case CODE_QUALITY = 'code_quality';
    case LEAKAGE = 'leakage';
}

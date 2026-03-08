<?php

declare(strict_types=1);

namespace Hilos\Guardian\Enums;

enum ActionType: string
{
    case CREATE_REPORT = 'create_report';
    case NOTIFY_USER = 'notify_user';
    case NOTIFY_AGENT = 'notify_agent';
    case CALL_API = 'call_api';
}

<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Which side defines a live database table. */
enum ChangeLogTableOwner: string
{
    case FRAMEWORK = 'framework';
    case PROJECT = 'project';
}

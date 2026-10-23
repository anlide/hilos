<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Source event of an immutable incident update. */
enum StatusUpdateKind: string
{
    case PUBLISHED = 'published';
    case SEVERITY_CHANGED = 'severity_changed';
    case RESOLVED = 'resolved';
    case TEAM = 'team';
}

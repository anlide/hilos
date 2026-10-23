<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Retained reason a continuous incident fact ended. */
enum StatusIncidentOutcome: string
{
    case RECOVERED_BEFORE_PUBLICATION = 'recovered_before_publication';
    case CANCELED_PUBLICATION = 'canceled_publication';
    case RESOLVED = 'resolved';
}

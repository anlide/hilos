<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Severity of a durable Status incident. */
enum StatusSeverity: string
{
    case PARTIAL_OUTAGE = 'partial_outage';
    case MAJOR_OUTAGE = 'major_outage';

    /**
     * @param self $other Another publicly recorded severity
     * @return self Greater of the two severities
     */
    public function maximum(self $other): self
    {
        return $this === self::MAJOR_OUTAGE || $other === self::MAJOR_OUTAGE ? self::MAJOR_OUTAGE : self::PARTIAL_OUTAGE;
    }
}

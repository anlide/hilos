<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

/** Where one staged snapshot generation stands on its way to publication. */
enum RtSnapshotStatus
{
    /** Input, preparation or a later change is still to be taken in. */
    case Building;

    /** Complete and current: publication may switch the roots now. */
    case Ready;

    /** The roots were switched; what follows publication goes on in portions. */
    case Published;

    /** Closed before publication: canceled, outrun by its history or no longer current. */
    case Canceled;

    /** A row of the generation failed its validator; nothing of it was published. */
    case Refused;
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

/** Logical holders are counted separately from physical, potentially shared pages. */
enum RtRowRole
{
    case Resident;
    case Candidate;
    case Ordinary;
    case Snapshot;
    case Transient;
    case Wire;
    case Retired;
}

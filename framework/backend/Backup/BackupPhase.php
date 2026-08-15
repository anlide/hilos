<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupPhase - where a backup creation run currently is.
 *
 * The counterpart of {@see RestorePhase} on the create side: the engine announces each phase it
 * enters, the child process prints it as a {@see BackupProgressMarker} line, and the supervising
 * agent turns it into the runtime anchor a progress bar is drawn from.
 *
 * The weights say how the wall-clock of a run divides between the phases. They are code constants
 * rather than measured per-run figures because measuring them would mean writing per-phase
 * durations into every sidecar and rescanning the whole store for a few percent of accuracy.
 */
enum BackupPhase: string
{
    /** Dumping every configured connection into the workdir. */
    case DUMPING = 'dumping';

    /** Packing the workdir into the archive. */
    case ARCHIVING = 'archiving';

    /** Hashing the finished archive. */
    case DIGESTING = 'digesting';

    /** Moving the archive and its sidecar into the store. */
    case PUBLISHING = 'publishing';

    /** What share of a run each phase takes; the entries sum to a whole run. */
    private const array WEIGHTS = [
        self::DUMPING->value => 0.70,
        self::ARCHIVING->value => 0.25,
        self::DIGESTING->value => 0.04,
        self::PUBLISHING->value => 0.01,
    ];

    /**
     * The share of a whole run this phase is expected to take.
     *
     * @return float Share of the run, between 0 and 1; the cases sum to 1
     */
    public function weight(): float
    {
        return self::WEIGHTS[$this->value];
    }

    /**
     * The share of a whole run already behind a run that has just entered this phase.
     *
     * Summed from the phases before it rather than written out again, so the two figures cannot
     * drift apart: the declaration order of the cases is the order a run passes through them.
     *
     * @return float Share of the run completed before this phase, between 0 and 1
     */
    public function weightBefore(): float
    {
        $before = 0.0;
        foreach (self::cases() as $case) {
            if ($case === $this) {
                break;
            }

            $before += $case->weight();
        }

        return $before;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

/**
 * Receives the worker's current protected-mode setting on the master's message path.
 */
interface ProtectedModeSettingsSink
{
    /**
     * The master must only remember the value: it cannot read the database or block here.
     *
     * @param bool $manualRestartIsNormal Whether a manual-window restart is routine
     */
    public function onProtectedModeSettings(bool $manualRestartIsNormal): void;
}

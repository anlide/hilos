<?php

declare(strict_types=1);

namespace Hilos\Users\Exception;

use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\View\Collection\HilosUserBlockSource;
use Hilos\Hilos;
use Hilos\Users\AccountBlockReader;

/**
 * Exception: the account block fact was asked for and there is nothing to read it from.
 *
 * Raised by {@see AccountBlockReader} instead of answering false. A block seam that fails open
 * on a wiring gap is the one outcome it may not have: the refusal names the wiring, a silent
 * false names nothing and lets a blocked account through. A project declaring
 * {@see HilosFeature::HILOS_USERS} does not reach this at runtime, because the activation check
 * refuses a declaration without a source first.
 *
 * A wiring defect found at runtime, and not a {@see DbCollectionNotReadableException}: that one
 * says a source exists and this process is not its reader, and the read guard raises it itself.
 * The two factories here say which of the other two cures applies - a missing declaration, or a
 * question asked in a process that runs without a database at all.
 */
final class UserBlockSourceMissingException extends LogicException
{
    /**
     * Creates the failure for a project that mounted no block source.
     *
     * @param class-string<Hilos> $hilosClass Project facade whose database context has no block source
     * @return self Exception instance
     */
    public static function forFacade(string $hilosClass): self
    {
        return new self(
            "No user block source: {$hilosClass} declares no database collection implementing "
            . HilosUserBlockSource::class . '; HilosFeature::HILOS_USERS requires one'
        );
    }

    /**
     * Creates the failure for a process that runs without a database layer.
     *
     * @return self Exception instance
     */
    public static function forDatabaseFreeProcess(): self
    {
        return new self(
            'No user block source: this process runs without a database layer, so the account block fact cannot be read here'
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Core\Exception\InvalidArgumentException;

/** The mode of a live column and, for noise, the reason shown on the screen. */
final readonly class JournalColumnPlacement
{
    /**
     * @param JournalColumnMode $mode How the column enters the journal
     * @param ?string $reason Non-empty explanation for a noisy column, null for every other mode
     * @throws InvalidArgumentException When the reason does not match the mode
     */
    public function __construct(
        public JournalColumnMode $mode,
        public ?string $reason = null,
    ) {
        if (($mode === JournalColumnMode::NOISE && ($reason === null || trim($reason) === ''))
            || ($mode !== JournalColumnMode::NOISE && $reason !== null)) {
            throw new InvalidArgumentException('A journal noise placement needs a non-empty reason, and other modes have none');
        }
    }
}

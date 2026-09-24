<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

/**
 * One backup code as the "Show codes" screen lists it (HIL-494): the code in its display
 * form and whether it was used. Used codes stay on the list, struck through, so a person
 * can tell a code they typed from a code they never had.
 */
final readonly class BackupCodeEntry
{
    /**
     * @param string $code Code in its display form (xxxxx-xxxxx)
     * @param bool $used Whether the code was already used
     */
    public function __construct(
        public string $code,
        public bool $used,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ProtectedModeDisableSignalData - initiator -> leader payload for PROTECTED_MODE_DISABLE.
 *
 * The initiator sends it once its operation has finished, asking the leader to lift the
 * freeze cluster-wide (the leader then broadcasts PROTECTED_MODE_LIFT to the followers).
 * There is only ever one freeze in flight, tracked on
 * {@see \Hilos\Runtime\State\Item\ProtectedModeRuntime}, so the disable request carries
 * no payload; the leader disables the mode it currently owns.
 */
final class ProtectedModeDisableSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @return array<string, mixed> Empty disable payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

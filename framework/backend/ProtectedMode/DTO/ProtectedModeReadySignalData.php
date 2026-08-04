<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeReadySignalData - leader -> initiator payload for PROTECTED_MODE_READY.
 *
 * The leader sends it once every node has reported quiesced and the mode has reached
 * active: the go-ahead for the initiator to run its destructive operation. The freeze
 * is already described by {@see ProtectedModeRuntime}, so the
 * ready hand-off carries no payload of its own; the signal itself is the readiness.
 */
final class ProtectedModeReadySignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @return array<string, mixed> Empty ready payload
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

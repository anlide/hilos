<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

/**
 * SignalDataDTO - Marker interface for signal data DTOs.
 *
 * All signal data DTOs must implement this interface.
 * Signal data DTOs must extend BaseDTO and implement SignalDataDTO.
 * This is a marker interface to distinguish signal data DTOs from other DTOs.
 */
// TODO: This marker is always implemented alongside SignalDataInterface but is
// never consumed as a type hint or in instanceof checks, so it currently
// duplicates SignalDataInterface. Decide whether to give it a distinct purpose
// or drop it and let SignalDataInterface be the single contract.
interface SignalDataDTO
{
}

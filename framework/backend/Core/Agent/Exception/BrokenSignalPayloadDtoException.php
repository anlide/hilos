<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Hilos\Core\Router\SignalDataInterface;

/**
 * Exception when the topology registry declares an inner payload DTO class that cannot be hydrated at all.
 *
 * Separates "the declared class is broken" from "the payload was rejected": here hydration
 * never starts, because the class-string does not resolve to a SignalDataInterface class.
 * A payload that fromArray() refused keeps its own contextual exception.
 */
class BrokenSignalPayloadDtoException extends AgentException
{
    /**
     * @param string $routeName Signal or command name the DTO class is declared for
     * @param string $dtoClass Declared inner payload DTO class-string
     */
    public function __construct(string $routeName, string $dtoClass)
    {
        parent::__construct(
            "Topology declares an unusable payload DTO for {$routeName}: {$dtoClass} does not exist"
            . ' or does not implement ' . SignalDataInterface::class,
        );
    }
}

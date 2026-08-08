<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Agent\Exception\BrokenSignalPayloadDtoException;

/**
 * Single hydration point for topology-declared inner payload DTOs.
 *
 * The three dispatch parsers (agent signal, page-routed agent signal, CLI command) keep
 * their own public signatures and contextual exceptions, but the class-string check and
 * the fromArray() call live here once. Any Throwable raised by fromArray() propagates
 * unchanged, so each caller can wrap it in its own "payload did not match" exception.
 */
final class SignalPayloadHydrator
{
    /**
     * @param array<string, mixed> $data Raw payload data
     * @param string $dtoClass Declared inner payload DTO class-string
     * @param string $routeName Signal or command name the class is declared for
     * @return SignalDataInterface Hydrated inner payload
     * @throws BrokenSignalPayloadDtoException When the declared class-string cannot be hydrated at all
     */
    public static function hydrate(array $data, string $dtoClass, string $routeName): SignalDataInterface
    {
        if (!class_exists($dtoClass) || !is_subclass_of($dtoClass, SignalDataInterface::class)) {
            throw new BrokenSignalPayloadDtoException($routeName, $dtoClass);
        }

        return $dtoClass::fromArray($data);
    }
}

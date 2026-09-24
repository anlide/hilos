<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library -> the session holder: the enrolment on the way in is confirmed (HIL-494).
 *
 * The wait stays - the person is let in by the Continue under the backup codes - but its mode
 * moves on, so a reload in between serves the ordinary code step rather than a second
 * enrolment ({@see AbstractSessionsLibraryAgent}).
 */
final class AuthSecondFactorSetupProvenSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token of the browser that enrolled
     */
    public function __construct(
        public readonly string $sessionToken,
    ) {
    }

    /**
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return ['sessionToken' => $this->sessionToken];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws InvalidFormatException When the payload names no session
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'sessionToken'));
    }
}

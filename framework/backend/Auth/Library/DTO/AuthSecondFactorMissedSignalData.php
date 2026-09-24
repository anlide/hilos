<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library -> the session holder: this browser sent a wrong second-factor code (HIL-494).
 *
 * The count of wrong codes is kept on the session row, which only the holder
 * ({@see AbstractSessionsLibraryAgent}) writes; the library that checked the code asks for the
 * count by this frame. At the ceiling the holder lets the wait go and sends the tabs back to
 * the address field.
 */
final class AuthSecondFactorMissedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token of the browser that sent the code
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

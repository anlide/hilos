<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library -> the session holder: this person's second factor is gone (HIL-494).
 *
 * Sent when the last authenticator is removed from the profile and when a delayed removal is
 * carried out. What the factor let the holder ({@see AbstractSessionsLibraryAgent}) keep goes
 * with it: the browsers trusted to skip the step, and every sign-in of the person still
 * waiting on a code that nothing can produce any more.
 */
final class AuthSecondFactorOffSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $userId Person whose second factor is gone
     */
    public function __construct(
        public readonly int $userId,
    ) {
    }

    /**
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return ['userId' => $this->userId];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws InvalidFormatException When the payload names no person
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, 'userId'));
    }
}

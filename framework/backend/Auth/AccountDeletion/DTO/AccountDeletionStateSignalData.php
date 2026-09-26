<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion\DTO;

use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * A person's account deletion state, as their tabs draw it (HIL-302).
 *
 * Built by {@see AccountDeletionStateProjector}; rides the profile page's subscription payload
 * for the first render and the person's group for every start and cancel after it. The
 * deletion that stands, or null when none does. Moments are server epoch ms.
 */
final class AccountDeletionStateSignalData extends BaseDTO implements SignalDataInterface
{
    public const string deletion = 'deletion';
    public const string requestedAt = 'requestedAt';
    public const string effectiveAt = 'effectiveAt';

    /**
     * @param ?array{requestedAt: int, effectiveAt: int} $deletion The scheduled deletion, or null when none stands
     */
    public function __construct(public readonly ?array $deletion)
    {
    }

    /**
     * @return array{deletion: ?array{requestedAt: int, effectiveAt: int}} Wire form
     */
    public function toArray(): array
    {
        return [self::deletion => $this->deletion];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When a member of the deletion is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        $deletion = self::optionalArray($data, self::deletion);

        return new static($deletion === null ? null : [
            self::requestedAt => self::requireInt($deletion, self::requestedAt),
            self::effectiveAt => self::requireInt($deletion, self::effectiveAt),
        ]);
    }
}

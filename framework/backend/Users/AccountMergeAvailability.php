<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;

/** Public occupancy facts, without protection details or a promise that a merge mutation will succeed. */
final readonly class AccountMergeAvailability
{
    public const string reserved = 'reserved';
    public const string available = 'available';

    /**
     * @param bool $reserved An existing preparation still holds this account
     * @param bool $available The account exists and has no merge or erasure occupancy
     */
    public function __construct(public bool $reserved, public bool $available)
    {
    }

    /** @return array{reserved: bool, available: bool} Public occupancy projection */
    public function toArray(): array
    {
        return [self::reserved => $this->reserved, self::available => $this->available];
    }

    /**
     * @param array<string, mixed> $data Exact public projection
     * @return self Checked flags without coercion or additional fields
     * @throws InvalidFormatException When either flag is absent, mistyped or accompanied by another field
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 2 || !isset($data[self::reserved], $data[self::available])
            || !is_bool($data[self::reserved]) || !is_bool($data[self::available])) {
            throw new InvalidFormatException('Merge availability requires exactly the reserved and available boolean fields.');
        }

        return new self($data[self::reserved], $data[self::available]);
    }

    /** @return array<string, WireField> Public derived facts for the admin view mode */
    public static function wireFields(): array
    {
        return [self::reserved => WireField::notPersonal(), self::available => WireField::notPersonal()];
    }
}

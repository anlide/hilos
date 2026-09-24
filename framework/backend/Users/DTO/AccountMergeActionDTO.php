<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Database\Identity\PasswordFate;

/**
 * Payload for merging another account into the user displayed on the Hilos user page (HIL-411).
 *
 * Both ids travel explicitly so the library can judge the pair at the write boundary. The
 * password fate is optional because a pair with at most one password needs no decision.
 */
final class AccountMergeActionDTO extends ActionPayloadDTO
{
    public const string survivorUserId = 'survivorUserId';
    public const string loserUserId = 'loserUserId';
    public const string passwordFate = 'passwordFate';

    /**
     * @param int $survivorUserId User id that absorbs the other account
     * @param int $loserUserId User id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password remains, or null when no choice was named
     */
    public function __construct(
        public readonly int $survivorUserId,
        public readonly int $loserUserId,
        public readonly ?PasswordFate $passwordFate,
    ) {
    }

    /** @return string Action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_MERGE;
    }

    /**
     * @param array<string, mixed> $data Raw payload, optionally wrapped in the action-data envelope
     * @return static Account-merge action payload
     * @throws InvalidFormatException When either id is missing or the password fate is unknown
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        $namedFate = self::optionalString($inner, self::passwordFate);
        $passwordFate = $namedFate === null ? null : PasswordFate::tryFrom($namedFate);
        if ($namedFate !== null && $passwordFate === null) {
            throw new InvalidFormatException('Unknown account-merge password fate');
        }

        return new static(
            survivorUserId: self::requireInt($inner, self::survivorUserId),
            loserUserId: self::requireInt($inner, self::loserUserId),
            passwordFate: $passwordFate,
        );
    }

    /** @return array<string, int|string> Account ids and the named password fate, when any */
    public function toArray(): array
    {
        $data = [
            self::survivorUserId => $this->survivorUserId,
            self::loserUserId => $this->loserUserId,
        ];
        if ($this->passwordFate !== null) {
            $data[self::passwordFate] = $this->passwordFate->value;
        }

        return $data;
    }
}

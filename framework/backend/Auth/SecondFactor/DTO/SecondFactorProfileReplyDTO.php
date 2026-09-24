<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Auth\SecondFactor\BackupCodeEntry;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * What a profile second-factor action answers beyond "done" (HIL-494).
 *
 * Four of the profile's submits hand something back that is shown once and kept nowhere a
 * reload reads: the secret of an app being connected, the backup codes of a new set, and the
 * list of codes on the "Show" screen. Each fills the members it answers and leaves the rest
 * out of the wire.
 */
final class SecondFactorProfileReplyDTO extends ActionReplyDTO
{
    public const string authenticatorId = 'authenticatorId';
    public const string secret = 'secret';
    public const string otpauthUri = 'otpauthUri';
    public const string backupCodes = 'backupCodes';
    public const string codes = 'codes';
    public const string code = 'code';
    public const string used = 'used';

    /**
     * @param ?int $authenticatorId Authenticator an enrolment started, or null
     * @param ?string $secret Base32 secret of that enrolment, or null
     * @param ?string $otpauthUri otpauth address of that secret, or null
     * @param ?list<string> $backupCodes Codes of a set just issued, in display form, or null
     * @param ?list<BackupCodeEntry> $codes The person's set as the "Show" screen lists it, or null
     */
    public function __construct(
        public readonly ?int $authenticatorId = null,
        public readonly ?string $secret = null,
        public readonly ?string $otpauthUri = null,
        public readonly ?array $backupCodes = null,
        public readonly ?array $codes = null,
    ) {
    }

    /**
     * @return array<string, mixed> Wire form; absent members are left out
     */
    public function toArray(): array
    {
        $data = [];
        if ($this->authenticatorId !== null) {
            $data[self::authenticatorId] = $this->authenticatorId;
        }
        if ($this->secret !== null) {
            $data[self::secret] = $this->secret;
        }
        if ($this->otpauthUri !== null) {
            $data[self::otpauthUri] = $this->otpauthUri;
        }
        if ($this->backupCodes !== null) {
            $data[self::backupCodes] = $this->backupCodes;
        }
        if ($this->codes !== null) {
            $data[self::codes] = array_map(
                static fn (BackupCodeEntry $entry): array => [self::code => $entry->code, self::used => $entry->used],
                $this->codes,
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored reply
     * @throws InvalidFormatException When a present member is of another type
     */
    public static function fromArray(array $data): static
    {
        $codes = null;
        $list = self::optionalArray($data, self::codes);
        if ($list !== null) {
            $codes = [];
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidFormatException('Backup code list carries an entry that is not a map');
                }
                $codes[] = new BackupCodeEntry(self::requireString($entry, self::code), self::requireBool($entry, self::used));
            }
        }

        return new static(
            self::optionalInt($data, self::authenticatorId),
            self::optionalString($data, self::secret),
            self::optionalString($data, self::otpauthUri),
            self::optionalStringList($data, self::backupCodes),
            $codes,
        );
    }
}

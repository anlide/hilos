<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Users\SecondFactorFate;
use JsonException;
use stdClass;

/** Immutable merge choices and the protection snapshot supplied with them. */
final readonly class AccountMergeChoice
{
    public const string passwordFate = 'passwordFate';
    public const string secondFactorFate = 'secondFactorFate';
    public const string expectedSurvivorHasSecondFactor = 'expectedSurvivorHasSecondFactor';
    public const string expectedLoserHasSecondFactor = 'expectedLoserHasSecondFactor';

    /**
     * @param ?PasswordFate $passwordFate Password to preserve, or an unnamed choice
     * @param ?SecondFactorFate $secondFactorFate Protection to preserve, or an unnamed choice
     * @param ?bool $expectedSurvivorHasSecondFactor Survivor's supplied protection snapshot
     * @param ?bool $expectedLoserHasSecondFactor Loser's supplied protection snapshot
     */
    public function __construct(
        public ?PasswordFate $passwordFate,
        public ?SecondFactorFate $secondFactorFate,
        public ?bool $expectedSurvivorHasSecondFactor,
        public ?bool $expectedLoserHasSecondFactor,
    ) {
    }

    /**
     * @param string $json Stored object with all four nullable choice fields
     * @return self Checked immutable choices
     * @throws InvalidJsonException When the stored value is malformed JSON
     * @throws InvalidFormatException When keys, fates or protection flags are invalid
     */
    public static function fromJson(string $json): self
    {
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidJsonException('The account merge choices must be valid JSON.', previous: $failure);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The account merge choices must be a JSON object.');
        }
        $fields = get_object_vars($object);
        $keys = [self::passwordFate, self::secondFactorFate, self::expectedSurvivorHasSecondFactor, self::expectedLoserHasSecondFactor];
        if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []) {
            throw new InvalidFormatException('The account merge choices require all four choice fields.');
        }
        $password = $fields[self::passwordFate];
        $secondFactor = $fields[self::secondFactorFate];
        if ($password !== null && (!is_string($password) || PasswordFate::tryFrom($password) === null)) {
            throw new InvalidFormatException('The account merge password choice is unknown.');
        }
        if ($secondFactor !== null && (!is_string($secondFactor) || SecondFactorFate::tryFrom($secondFactor) === null)) {
            throw new InvalidFormatException('The account merge second-factor choice is unknown.');
        }
        foreach ([self::expectedSurvivorHasSecondFactor, self::expectedLoserHasSecondFactor] as $key) {
            if ($fields[$key] !== null && !is_bool($fields[$key])) {
                throw new InvalidFormatException('The account merge protection flags must be boolean or null.');
            }
        }

        return new self(
            $password === null ? null : PasswordFate::from($password),
            $secondFactor === null ? null : SecondFactorFate::from($secondFactor),
            $fields[self::expectedSurvivorHasSecondFactor],
            $fields[self::expectedLoserHasSecondFactor],
        );
    }

    /** @return string Complete JSON object, preserving explicit null choices and flags */
    public function toJson(): string
    {
        return json_encode([
            self::passwordFate => $this->passwordFate?->value,
            self::secondFactorFate => $this->secondFactorFate?->value,
            self::expectedSurvivorHasSecondFactor => $this->expectedSurvivorHasSecondFactor,
            self::expectedLoserHasSecondFactor => $this->expectedLoserHasSecondFactor,
        ]);
    }

    /**
     * @param self $other Choices to compare
     * @return bool Whether every fate and snapshot flag matches exactly
     */
    public function equals(self $other): bool
    {
        return $this->passwordFate === $other->passwordFate
            && $this->secondFactorFate === $other->secondFactorFate
            && $this->expectedSurvivorHasSecondFactor === $other->expectedSurvivorHasSecondFactor
            && $this->expectedLoserHasSecondFactor === $other->expectedLoserHasSecondFactor;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountMergeChoiceTest extends TestCase
{
    /** @return iterable<string, array{?PasswordFate, ?SecondFactorFate, ?bool, ?bool}> Nullable and named choices */
    public static function choices(): iterable
    {
        yield 'all null' => [null, null, null, null];
        yield 'survivor' => [PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false];
        yield 'loser and both' => [PasswordFate::LOSER, SecondFactorFate::BOTH, false, true];
        yield 'neither password' => [PasswordFate::NONE, null, null, false];
    }

    /**
     * @param ?PasswordFate $passwordFate Password choice
     * @param ?SecondFactorFate $secondFactorFate Protection choice
     * @param ?bool $survivor Survivor snapshot flag
     * @param ?bool $loser Loser snapshot flag
     */
    #[DataProvider('choices')]
    public function testRoundTripPreservesEveryNullableValue(
        ?PasswordFate $passwordFate,
        ?SecondFactorFate $secondFactorFate,
        ?bool $survivor,
        ?bool $loser,
    ): void {
        $choice = new AccountMergeChoice($passwordFate, $secondFactorFate, $survivor, $loser);
        $fields = json_decode($choice->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'passwordFate' => $passwordFate?->value,
            'secondFactorFate' => $secondFactorFate?->value,
            'expectedSurvivorHasSecondFactor' => $survivor,
            'expectedLoserHasSecondFactor' => $loser,
        ], $fields);
        $copy = AccountMergeChoice::fromJson($choice->toJson());
        self::assertSame($passwordFate, $copy->passwordFate);
        self::assertSame($secondFactorFate, $copy->secondFactorFate);
        self::assertSame($survivor, $copy->expectedSurvivorHasSecondFactor);
        self::assertSame($loser, $copy->expectedLoserHasSecondFactor);
        self::assertTrue($choice->equals($copy));
        self::assertTrue($copy->equals($choice));
    }

    /** @return iterable<string, array{string}> Malformed objects, unknown fates and extra keys */
    public static function invalidChoices(): iterable
    {
        yield 'malformed JSON' => ['{'];
        yield 'array root' => ['[]'];
        yield 'null root' => ['null'];
        yield 'string root' => ['"choice"'];
        $fields = json_decode(new AccountMergeChoice(null, null, null, null)->toJson(), true);
        foreach (['passwordFate', 'secondFactorFate'] as $key) {
            foreach (['unknown', 'SURVIVOR', '', 0, true, [], new AccountMergeChoice(null, null, null, null)] as $index => $value) {
                $invalid = $fields;
                $invalid[$key] = $value;
                yield $key . '-' . $index => [json_encode($invalid)];
            }
        }
        $fields['unexpected'] = null;
        yield 'extra key' => [json_encode($fields)];
    }

    /** @param string $json Invalid choice object */
    #[DataProvider('invalidChoices')]
    public function testInvalidChoiceObjectsAreRefused(string $json): void
    {
        $this->expectException(ValidationException::class);
        AccountMergeChoice::fromJson($json);
    }

    /** @return iterable<string, array{string}> Each missing key, even when its value would be null */
    public static function missingKeys(): iterable
    {
        $fields = json_decode(new AccountMergeChoice(null, null, null, null)->toJson(), true);
        foreach (array_keys($fields) as $key) {
            $missing = $fields;
            unset($missing[$key]);
            yield $key => [json_encode($missing)];
        }
    }

    /** @param string $json Object missing one required nullable field */
    #[DataProvider('missingKeys')]
    public function testEveryKeyMustBePresent(string $json): void
    {
        $this->expectException(ValidationException::class);
        AccountMergeChoice::fromJson($json);
    }

    /** @return iterable<string, array{string}> Values that must not coerce into protection flags */
    public static function nonBooleanFlags(): iterable
    {
        $fields = json_decode(new AccountMergeChoice(null, null, null, null)->toJson(), true);
        foreach (['expectedSurvivorHasSecondFactor', 'expectedLoserHasSecondFactor'] as $key) {
            foreach ([0, 1, 'true', 'false', '0', [], 1.0] as $index => $value) {
                $invalid = $fields;
                $invalid[$key] = $value;
                yield $key . '-' . $index => [json_encode($invalid)];
            }
        }
    }

    /** @param string $json Object with a non-boolean flag */
    #[DataProvider('nonBooleanFlags')]
    public function testProtectionFlagsAreStrictlyBooleanOrNull(string $json): void
    {
        $this->expectException(ValidationException::class);
        AccountMergeChoice::fromJson($json);
    }

    public function testEqualityComparesAllFieldsAndIgnoresJsonKeyOrder(): void
    {
        $choice = new AccountMergeChoice(PasswordFate::LOSER, SecondFactorFate::BOTH, true, false);
        $fields = json_decode($choice->toJson(), true);
        self::assertTrue($choice->equals(AccountMergeChoice::fromJson(json_encode(array_reverse($fields, true)))));
        foreach ([
            new AccountMergeChoice(null, SecondFactorFate::BOTH, true, false),
            new AccountMergeChoice(PasswordFate::LOSER, null, true, false),
            new AccountMergeChoice(PasswordFate::LOSER, SecondFactorFate::BOTH, null, false),
            new AccountMergeChoice(PasswordFate::LOSER, SecondFactorFate::BOTH, true, null),
        ] as $different) {
            self::assertFalse($choice->equals($different));
            self::assertFalse($different->equals($choice));
        }
    }
}

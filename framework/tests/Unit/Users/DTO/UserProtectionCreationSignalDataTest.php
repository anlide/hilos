<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Users\DTO\SecondFactorEnrollmentData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartSignalData;
use Hilos\Users\DTO\UserSecondFactorEnrollStartDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueSignalData;
use Hilos\Users\DTO\UserSecondFactorCodesIssueDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRequestSignalData;
use Hilos\Users\DTO\UserSecondFactorResetRequestDoneSignalData;
use Hilos\Users\DTO\IdentityAddData;
use Hilos\Users\DTO\OAuthIdentityAddData;
use Hilos\Users\DTO\PasskeyIdentityAddData;
use Hilos\Users\DTO\PasswordIdentityAddData;
use Hilos\Users\DTO\SmsIdentityAddData;
use Hilos\Users\DTO\UserIdentityAddDoneSignalData;
use Hilos\Users\DTO\UserIdentityAddSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserProtectionCreationSignalDataTest extends TestCase
{
    #[DataProvider('materials')]
    public function testFourArmsRoundTripThroughAskAnswerAndAgentEnvelope(IdentityAddData $identity): void
    {
        $ask = self::ask($identity);
        self::assertEquals($identity, IdentityAddData::fromArray($identity->toArray()));
        self::assertEquals($ask, UserIdentityAddSignalData::fromJson($ask->toJson()));
        $done = UserIdentityAddDoneSignalData::ok($ask, 42);
        self::assertEquals($done, UserIdentityAddDoneSignalData::fromJson($done->toJson()));
        $envelope = new AgentSignalData($done);
        $restored = AgentSignalData::fromArray($envelope->toArray());
        self::assertSame($envelope->toArray(), $restored->toArray());
        if ($identity instanceof PasskeyIdentityAddData) {
            self::assertSame("\x00\xffhandle", Base64Url::decode($identity->userHandle));
            self::assertSame($identity->deviceName, $restored->toArray()[SignalPayloadConstants::FIELD_DATA][UserIdentityAddDoneSignalData::ask]
                [UserIdentityAddSignalData::identity][PasskeyIdentityAddData::deviceName]);
        }
    }

    /** @return iterable<string, array{IdentityAddData}> Each supported arm and both device-label values */
    public static function materials(): iterable
    {
        yield 'password' => [new PasswordIdentityAddData('email@example.test', 'hash')];
        yield 'sms' => [new SmsIdentityAddData('+15551234567')];
        yield 'oauth' => [new OAuthIdentityAddData('oauth:github', 'subject', null)];
        yield 'named passkey' => [self::passkey('Chrome on Linux')];
        yield 'unnamed passkey' => [self::passkey(null)];
    }

    #[DataProvider('malformedMaterial')]
    public function testMalformedMaterialIsRefused(array $row): void
    {
        $this->expectException(InvalidFormatException::class);
        IdentityAddData::fromArray($row);
    }

    /** @return iterable<string, array{array<string, mixed>}> Required keys, tags and scalar/list types */
    public static function malformedMaterial(): iterable
    {
        foreach (self::materials() as $name => [$identity]) {
            foreach (array_keys($identity->toArray()) as $key) {
                $row = $identity->toArray();
                unset($row[$key]);
                yield $name . ' missing ' . $key => [$row];
                $row = $identity->toArray();
                $row[$key] = false;
                yield $name . ' wrong ' . $key => [$row];
            }
        }
        foreach ([
            [IdentityAddData::type => 'magic_link'],
            [PasskeyIdentityAddData::algorithm => 0],
            [PasskeyIdentityAddData::signCount => -1],
            [PasskeyIdentityAddData::userHandle => '*invalid*'],
            [PasskeyIdentityAddData::credentialId => 'padded=='],
            [PasskeyIdentityAddData::transports => ['usb', 5]],
            [PasskeyIdentityAddData::transports => ['foreign' => 'usb']],
        ] as $index => $patch) {
            yield 'invalid value ' . $index => [[...self::passkey(null)->toArray(), ...$patch]];
        }
    }

    #[DataProvider('malformedAsk')]
    public function testMalformedAskIsRefused(array $patch): void
    {
        $this->expectException(InvalidFormatException::class);
        UserIdentityAddSignalData::fromArray([...self::ask(self::passkey(null))->toArray(), ...$patch]);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid addressee and envelope fields */
    public static function malformedAsk(): iterable
    {
        yield 'user' => [[UserIdentityAddSignalData::userId => 0]];
        yield 'session' => [[UserIdentityAddSignalData::sessionId => -1]];
        yield 'reply' => [[UserIdentityAddSignalData::replySignal => HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE]];
        yield 'success words' => [[UserIdentityAddSignalData::successMessage => 'done']];
        yield 'trip hash' => [[UserIdentityAddSignalData::tripKeyHash => false]];
    }

    public function testMissingOrWrongDeviceNameIsRefusedInsideTheAskAndAnswer(): void
    {
        foreach ([null, 17] as $wrong) {
            $row = self::ask(self::passkey(null))->toArray();
            if ($wrong === null) {
                unset($row[UserIdentityAddSignalData::identity][PasskeyIdentityAddData::deviceName]);
            } else {
                $row[UserIdentityAddSignalData::identity][PasskeyIdentityAddData::deviceName] = $wrong;
            }
            try {
                UserIdentityAddSignalData::fromArray($row);
                self::fail('A missing or non-string device name must not enter the owner ask');
            } catch (InvalidFormatException $failure) {
                self::assertStringContainsString(PasskeyIdentityAddData::deviceName, $failure->getMessage());
            }
            $answer = UserIdentityAddDoneSignalData::ok(self::ask(self::passkey(null)), 1)->toArray();
            $answer[UserIdentityAddDoneSignalData::ask] = $row;
            try {
                UserIdentityAddDoneSignalData::fromArray($answer);
                self::fail('Malformed material must not enter the returned ask');
            } catch (InvalidFormatException $failure) {
                self::assertStringContainsString(PasskeyIdentityAddData::deviceName, $failure->getMessage());
            }
        }
    }

    public function testFailureRoundTripHasNoIdentityId(): void
    {
        $done = UserIdentityAddDoneSignalData::refused(self::ask(self::passkey(null)), ActionRefusal::said('Refused'));
        self::assertEquals($done, UserIdentityAddDoneSignalData::fromArray($done->toArray()));
        self::assertNull($done->identityId);
    }

    #[DataProvider('invalidOutcomes')]
    public function testSuccessAndFailureAreExclusive(?int $identityId, ?string $error, ?string $type, ?string $detail): void
    {
        $this->expectException(InvalidFormatException::class);
        new UserIdentityAddDoneSignalData(self::ask(self::passkey(null)), $identityId, $error, $type, $detail);
    }

    /** @return iterable<string, array{?int, ?string, ?string, ?string}> Contradictory or absent outcomes */
    public static function invalidOutcomes(): iterable
    {
        yield 'absent' => [null, null, null, null];
        yield 'zero id' => [0, null, null, null];
        yield 'id and refusal' => [1, 'Refused', null, null];
        yield 'success with diagnostic' => [1, null, 'Failure', null];
        yield 'success with detail' => [1, null, null, 'detail'];
    }

    #[DataProvider('creations')]
    public function testCreationHandoverAndCommittedResultRoundTrip(object $ask, object $done): void
    {
        self::assertEquals($ask, $ask::fromJson($ask->toJson()));
        self::assertEquals($done, $done::fromJson($done->toJson()));
        $envelope = new AgentSignalData($done);
        self::assertSame($envelope->toArray(), AgentSignalData::fromArray($envelope->toArray())->toArray());
        $refused = $done::refused($ask, ActionRefusal::said('Refused'));
        self::assertEquals($refused, $done::fromArray($refused->toArray()));
    }

    #[DataProvider('malformedCreations')]
    public function testCreationParsingRejectsMissingTypesAndMixedOutcomes(string $class, array $row): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($row);
    }

    /** @return iterable<string, array{object, object}> Each owner creation and committed material */
    public static function creations(): iterable
    {
        $enroll = new UserSecondFactorEnrollStartSignalData(7, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START_DONE,
            'key', 'request', HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START, null);
        yield 'enrollment' => [$enroll, new UserSecondFactorEnrollStartDoneSignalData(
            $enroll, new SecondFactorEnrollmentData(21, 'secret', 'otpauth://totp/app'), null, null, null,
        )];
        $codes = new UserSecondFactorCodesIssueSignalData(7, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE_DONE,
            'key', 'request', HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW, null);
        yield 'codes' => [$codes, new UserSecondFactorCodesIssueDoneSignalData($codes, ['abcde-fghij'], null, null, null)];
        $reset = new UserSecondFactorResetRequestSignalData(7, HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST_DONE,
            'key', 'request', HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST, null);
        yield 'reset' => [$reset, new UserSecondFactorResetRequestDoneSignalData($reset, 33, 1900000000000, null, null, null)];
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Strict envelope and outcome failures */
    public static function malformedCreations(): iterable
    {
        foreach (self::creations() as $name => [$ask, $done]) {
            foreach ([$ask, $done] as $dto) {
                foreach (array_keys($dto->toArray()) as $key) {
                    $row = $dto->toArray();
                    unset($row[$key]);
                    yield $name . $dto::class . ' missing ' . $key => [$dto::class, $row];
                    $row = $dto->toArray();
                    $row[$key] = false;
                    yield $name . $dto::class . ' wrong ' . $key => [$dto::class, $row];
                }
            }
            yield $name . ' overlapping refusal' => [$done::class, [...$done->toArray(), $done::error => 'Refused']];
            yield $name . ' success words' => [$ask::class, [...$ask->toArray(), $ask::successMessage => 'Done']];
            yield $name . ' absent success' => [$done::class, array_replace($done->toArray(), match ($name) {
                'enrollment' => [UserSecondFactorEnrollStartDoneSignalData::enrollment => null],
                'codes' => [UserSecondFactorCodesIssueDoneSignalData::codes => null],
                'reset' => [UserSecondFactorResetRequestDoneSignalData::resetId => null],
            })];
        }
        $enroll = iterator_to_array(self::creations())['enrollment'][1];
        foreach (array_keys($enroll->enrollment->toArray()) as $key) {
            $row = $enroll->toArray();
            unset($row[UserSecondFactorEnrollStartDoneSignalData::enrollment][$key]);
            yield 'enrollment missing material ' . $key => [$enroll::class, $row];
            $row = $enroll->toArray();
            $row[UserSecondFactorEnrollStartDoneSignalData::enrollment][$key] = false;
            yield 'enrollment wrong material ' . $key => [$enroll::class, $row];
        }
        $codes = iterator_to_array(self::creations())['codes'][1];
        yield 'codes wrong element' => [$codes::class, [...$codes->toArray(), UserSecondFactorCodesIssueDoneSignalData::codes => [42]]];
        yield 'codes associative' => [$codes::class, [...$codes->toArray(), UserSecondFactorCodesIssueDoneSignalData::codes => ['key' => 'code']]];
        $reset = iterator_to_array(self::creations())['reset'][1];
        yield 'reset half refusal' => [$reset::class, [...$reset->toArray(), UserSecondFactorResetRequestDoneSignalData::error => 'Refused',
            UserSecondFactorResetRequestDoneSignalData::resetId => null]];
    }

    private static function ask(IdentityAddData $identity): UserIdentityAddSignalData
    {
        return new UserIdentityAddSignalData(7, 8, $identity, null,
            HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE, 'accept', 'request', HilosSignalConstants::PROFILE_SET_PASSWORD, null);
    }

    private static function passkey(?string $deviceName): PasskeyIdentityAddData
    {
        return new PasskeyIdentityAddData(Base64Url::encode('credential'), 'pem', PasskeyAlgorithm::Es256->value, 0,
            ['usb'], 'aaguid', Base64Url::encode("\x00\xffhandle"), $deviceName);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeAdmissionManifest;
use Hilos\Auth\AccountOperation\AccountMergeBackupCodeRow;
use Hilos\Auth\AccountOperation\AccountMergeHandover;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeIdentityRow;
use Hilos\Auth\AccountOperation\AccountMergePasskeyRow;
use Hilos\Auth\AccountOperation\AccountMergeRowRevision;
use Hilos\Auth\AccountOperation\AccountMergeSecondFactorRow;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataEnvelope;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Identity\IdentityType;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The new frame composes existing value serializers and preserves its receive address. */
final class AccountMergeHandoverSignalDataTest extends TestCase
{
    public function testExactRoundTripKeepsReceiveDistinctFromGiveAndUsesTypedValues(): void
    {
        $data = self::frame()->toArray();
        self::assertSame(['operationId', 'stage', 'assignmentId', 'ownerIndex', 'handover'], array_keys($data));
        self::assertSame(['copy', 'admission'], array_keys($data['handover']));
        self::assertSame(13, $data['assignmentId']);
        self::assertSame(11, $data['handover']['copy']['assignmentId']);
        self::assertSame('7', $data['ownerIndex']);
        self::assertSame('go', $data['stage']);
        $parsed = AccountMergeHandoverSignalData::fromArray(array_reverse($data, true));
        self::assertInstanceOf(AccountMergeHandover::class, $parsed->handover);
        self::assertSame($data, $parsed->toArray());
        self::assertSame($data, AccountMergeHandoverSignalData::fromJson(json_encode($data, JSON_THROW_ON_ERROR))->toArray());
    }

    public function testAllRowsBinaryHandleNullableAnchorsWaitAndOriginalRevisionsSurvive(): void
    {
        $frame = AccountMergeHandoverSignalData::fromJson(self::frame()->toJson());
        self::assertSame("\xff\x00\x80", $frame->handover->copy->passkeyCredentials[0]->userHandle);
        self::assertSame('_wCA', $frame->toArray()['handover']['copy']['passkeyCredentials'][0]['userHandle']);
        self::assertSame('private-password-hash', $frame->handover->copy->identities[0]->secret);
        self::assertSame('private-totp-secret', $frame->handover->copy->secondFactors[0]->secret);
        self::assertSame('private-backup-code', $frame->handover->copy->secondFactorBackupCodes[0]->code);
        self::assertSame(4, $frame->handover->admission->secondFactorBackupCodes[0]->revision);
        foreach ([null, 0, 14] as $wait) {
            $data = $frame->toArray();
            $data['handover']['copy']['effectiveWaitDays'] = $wait;
            $data['handover']['copy']['loserPasswordIdentityId'] = null;
            $data['handover']['copy']['survivorPasswordIdentityId'] = null;
            self::assertSame($data, AccountMergeHandoverSignalData::fromArray($data)->toArray());
        }
    }

    /** @return iterable<string, array{array}> Incomplete, mistyped and extra object fields */
    public static function invalidFields(): iterable
    {
        $frame = self::frame()->toArray();
        foreach (['root' => $frame, 'handover' => $frame['handover']] as $level => $fields) {
            foreach ($fields as $key => $value) {
                $wrong = $fields;
                unset($wrong[$key]);
                yield "$level missing $key" => [$level === 'root' ? $wrong : array_replace($frame, ['handover' => $wrong])];
                foreach ([null, false, 1.5, [], new SignalData()] as $index => $bad) {
                    if (get_debug_type($bad) === get_debug_type($value)) {
                        continue;
                    }
                    $wrong = array_replace($fields, [$key => $bad]);
                    yield "$level wrong $key $index" => [$level === 'root' ? $wrong : array_replace($frame, ['handover' => $wrong])];
                }
            }
            $wrong = $fields + ['private-unknown-key' => 'private-extra-value'];
            yield "$level extra" => [$level === 'root' ? $wrong : array_replace($frame, ['handover' => $wrong])];
        }
        foreach (['operationId', 'assignmentId'] as $key) {
            yield "$key string" => [array_replace($frame, [$key => '1'])];
        }
        yield 'index integer' => [array_replace($frame, ['ownerIndex' => 7])];
    }

    /** @param array $data Broken transport fields */
    #[DataProvider('invalidFields')]
    public function testStrictKeysAndTypesRejectBrokenFrames(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeHandoverSignalData::fromArray($data);
    }

    /** @return iterable<string, array{array}> Cross-field disagreement at construction as well as hydration */
    public static function wrongCoordinates(): iterable
    {
        foreach (['operationId' => [0, -1, 999], 'assignmentId' => [0, -1, 11],
            'stage' => ['preparing', 'cancelling', 'GO', ''], 'ownerIndex' => ['9', '07', '+7', '7 ', '0', '-7', '7.0']] as $key => $values) {
            foreach ($values as $index => $value) {
                yield "$key $index" => [array_replace(self::frame()->toArray(), [$key => $value])];
            }
        }
    }

    /** @param array $data Invalid immutable coordinates */
    #[DataProvider('wrongCoordinates')]
    public function testConstructionAndHydrationRejectInconsistentCoordinates(array $data): void
    {
        foreach ([fn () => AccountMergeHandoverSignalData::fromArray($data),
            fn () => new AccountMergeHandoverSignalData($data['operationId'], $data['stage'], $data['assignmentId'],
                $data['ownerIndex'], self::frame()->handover)] as $parse) {
            try {
                $parse();
                self::fail('The inconsistent handover address was accepted.');
            } catch (InvalidFormatException $failure) {
                self::assertStringContainsString('handover', $failure->getMessage());
            }
        }
    }

    public function testEnvelopeRouterAndSerializedFrameKeepReceiptMetadataAndOriginalSender(): void
    {
        $dto = self::frame();
        $encoded = SignalDataEnvelope::encode($dto);
        self::assertSame($dto->toArray(), SignalDataEnvelope::decode($encoded[SignalPayloadConstants::FIELD_DATA],
            $encoded[SignalPayloadConstants::FIELD_DATA_TYPE])->toArray());
        $source = new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, '9');
        $signal = new SignalDTO($source, new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER), new AgentSignalData($dto, 23), ['trace' => 'kept']);
        $restored = SignalDTO::fromArray(json_decode(json_encode($signal->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($signal->toArray(), $restored->toArray());
        self::assertSame('agent/hilos_user#9', SignalSource::describe($restored->signalSource));
        $router = new MergeHandoverTestRouter();
        self::assertEquals([new AgentDestination(HilosAgentType::HILOS_USER, '7')], $router->getDestinations($restored));
        $hydrated = $router->createAgentSignalPayloadDTO(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER,
            new AgentSignalData(new SignalData($dto->toArray()), 23));
        self::assertInstanceOf(AccountMergeHandoverSignalData::class, $hydrated->data);
        self::assertSame(23, $hydrated->receiptId);
        self::assertSame($dto->toArray(), $hydrated->data->toArray());
    }

    public function testDiagnosticsAndHydrationFailureLogContainNoPrivatePayloadValues(): void
    {
        $bad = self::frame()->toArray();
        $bad['handover']['copy']['secondFactors'][0]['createdAt'] = 'private-invalid-value';
        ob_start();
        $fallback = SignalDataEnvelope::decode($bad, AccountMergeHandoverSignalData::class);
        $logged = (string)ob_get_clean();
        self::assertInstanceOf(SignalData::class, $fallback);
        self::assertStringContainsString(AccountMergeHandoverSignalData::class, $logged);
        try {
            new MergeHandoverTestRouter()->createAgentSignalPayloadDTO(HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER,
                new AgentSignalData(new SignalData($bad)));
            self::fail('The malformed handover was accepted.');
        } catch (InvalidAgentSignalPayloadException $failure) {
            $logged .= $failure->getMessage() . $failure->getPrevious()->getMessage();
        }
        foreach (['private-password-hash', 'private-totp-secret', 'private-backup-code', 'private-invalid-value'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }

    /** @return AccountMergeHandoverSignalData Typed frame containing every existing row kind */
    private static function frame(): AccountMergeHandoverSignalData
    {
        return new AccountMergeHandoverSignalData(2, AccountMergeStage::Go->value, 13, '7', new AccountMergeHandover(
            new AccountMergeHandoverCopy(2, 11, 9, 7, 31, 6, 14,
                [new AccountMergeIdentityRow(31, 7, IdentityType::PASSWORD, 'person@example.test', 'private-password-hash',
                    null, true, null, null, -1, 0)],
                [new AccountMergePasskeyRow(41, 31, 7, 'credential', 'public-key', -7, 1, null, null, "\xff\x00\x80", null, null, 0)],
                [new AccountMergeSecondFactorRow(51, 7, 'App', 'private-totp-secret', null, 0, null, -1)],
                [new AccountMergeBackupCodeRow(61, 7, 'private-backup-code', null, -1)]),
            new AccountMergeAdmissionManifest([new AccountMergeRowRevision(31, 1)], [new AccountMergeRowRevision(41, 2)],
                [new AccountMergeRowRevision(51, 3)], [new AccountMergeRowRevision(61, 4)]),
        ));
    }
}

final class MergeHandoverTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture with the inherited personal declaration */
    protected function hilosClass(): string
    {
        return MergeHandoverTestHilos::class;
    }
}

abstract class MergeHandoverTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => MergeHandoverTestUser::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class MergeHandoverTestUser extends AbstractUserAgent
{
}

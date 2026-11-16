<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Passkey refusal identity survives both DTO serialization and agent transport hydration. */
final class UserPasskeyUseDoneSignalDataTest extends TestCase
{
    /** @return array<string, array{?ActionRefusal, bool}> Successful, ordinary refused and erasing outcomes */
    public static function outcomes(): array
    {
        return [
            'success' => [null, false],
            'ordinary refusal' => [ActionRefusal::fromThrowable(new DatabaseException('disk full')), false],
            'erasing refusal' => [ActionRefusal::fromThrowable(new AccountErasingException(
                AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE,
            )), true],
        ];
    }

    /**
     * @param ?ActionRefusal $refusal Original outcome
     * @param bool $erasing Whether erasure refused the use
     */
    #[DataProvider('outcomes')]
    public function testTheOutcomeRoundTrips(?ActionRefusal $refusal, bool $erasing): void
    {
        $done = UserPasskeyUseDoneSignalData::to(self::ask(), $refusal, $erasing);
        $payload = self::overTheWire($done->toArray());

        self::assertArrayHasKey('erasing', $payload);
        self::assertSame($erasing, $payload['erasing']);
        $restored = UserPasskeyUseDoneSignalData::fromArray($payload);
        self::assertEquals($done, $restored);
        self::assertSame($refusal?->reason, $restored->error);
        self::assertSame($refusal?->errorType, $restored->errorType);
        self::assertSame($refusal?->errorDetail, $restored->errorDetail);
    }

    public function testTheFactoryDefaultsToAnOrdinaryOutcome(): void
    {
        self::assertFalse(UserPasskeyUseDoneSignalData::to(self::ask(), null)->erasing);
    }

    /** @return array<string, array{array<string, mixed>}> Missing flag and non-boolean values */
    public static function malformedFlags(): array
    {
        return [
            'missing' => [[]],
            'null' => [['erasing' => null]],
            'zero' => [['erasing' => 0]],
            'one' => [['erasing' => 1]],
            'text' => [['erasing' => 'true']],
            'array' => [['erasing' => []]],
        ];
    }

    /** @param array<string, mixed> $flag Malformed flag or no flag */
    #[DataProvider('malformedFlags')]
    public function testTheWireRequiresABoolean(array $flag): void
    {
        $payload = UserPasskeyUseDoneSignalData::to(self::ask(), null)->toArray();
        unset($payload[UserPasskeyUseDoneSignalData::erasing]);
        $this->expectException(InvalidFormatException::class);

        UserPasskeyUseDoneSignalData::fromArray($payload + $flag);
    }

    public function testAnErasingSuccessCannotBeConstructed(): void
    {
        $this->expectException(InvalidFormatException::class);

        new UserPasskeyUseDoneSignalData(self::ask(), null, null, null, erasing: true);
    }

    public function testTheFactoryRefusesAnErasingSuccess(): void
    {
        $this->expectException(InvalidFormatException::class);

        UserPasskeyUseDoneSignalData::to(self::ask(), null, erasing: true);
    }

    public function testTheWireRefusesAnErasingSuccess(): void
    {
        $payload = UserPasskeyUseDoneSignalData::to(self::ask(), null)->toArray();
        $payload[UserPasskeyUseDoneSignalData::erasing] = true;
        $this->expectException(InvalidFormatException::class);

        UserPasskeyUseDoneSignalData::fromArray($payload);
    }

    /**
     * @param ?ActionRefusal $refusal Original outcome
     * @param bool $erasing Whether erasure refused the use
     */
    #[DataProvider('outcomes')]
    public function testAgentTransportHydratesTheOutcome(?ActionRefusal $refusal, bool $erasing): void
    {
        $done = UserPasskeyUseDoneSignalData::to(self::ask(), $refusal, $erasing);
        $raw = new AgentSignalData(new SignalData(self::overTheWire($done->toArray())), 701);
        $router = $this->getMockBuilder(SignalRouter::class)->onlyMethods(['hilosClass'])->getMock();
        $router->method('hilosClass')->willReturn(PasskeyUseDoneHilos::class);

        $hydrated = $router->createAgentSignalPayloadDTO(HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE, $raw);

        self::assertInstanceOf(UserPasskeyUseDoneSignalData::class, $hydrated->data);
        self::assertEquals($done, $hydrated->data);
        self::assertSame(701, $hydrated->receiptId);
        self::assertEquals($hydrated, AgentSignalData::fromArray(self::overTheWire($hydrated->toArray())));
    }

    /** @param array<string, mixed> $flag Malformed flag or no flag */
    #[DataProvider('malformedFlags')]
    public function testAgentHydrationRejectsAMalformedFlag(array $flag): void
    {
        $payload = UserPasskeyUseDoneSignalData::to(self::ask(), null)->toArray();
        unset($payload[UserPasskeyUseDoneSignalData::erasing]);
        $router = $this->getMockBuilder(SignalRouter::class)->onlyMethods(['hilosClass'])->getMock();
        $router->method('hilosClass')->willReturn(PasskeyUseDoneHilos::class);
        $this->expectException(InvalidAgentSignalPayloadException::class);

        $router->createAgentSignalPayloadDTO(
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            new AgentSignalData(new SignalData($payload + $flag)),
        );
    }

    /** @return UserPasskeyUseSignalData Tracked sign-in ask */
    private static function ask(): UserPasskeyUseSignalData
    {
        return new UserPasskeyUseSignalData(
            userId: 7,
            passkeyId: 5,
            signCount: 12,
            operation: null,
            sessionTokenHash: null,
            replySignal: HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            acceptKey: 'passkey-ak',
            requestId: 'passkey-request',
            action: HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM,
            successMessage: null,
        );
    }

    /**
     * @param array<string, mixed> $payload Transport payload
     * @return array<string, mixed> JSON-decoded payload
     */
    private static function overTheWire(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}

/** Uses the production library's inherited signal declaration for the hydration test. */
abstract class PasskeyUseDoneHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => AbstractUsersLibraryAgent::class],
    ];
}

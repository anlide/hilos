<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserPushSubscribeSignalDataTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> Optional handover and device fields */
    public static function optionalFields(): iterable
    {
        yield 'tracked' => ['request-7', 'Firefox', 'Saved'];
        yield 'untracked' => [null, null, null];
    }

    /**
     * @param ?string $requestId Waiting request, or null
     * @param ?string $userAgent Device user agent, or null
     * @param ?string $successMessage Optional success sentence
     */
    #[DataProvider('optionalFields')]
    public function testAllFieldsRoundtrip(?string $requestId, ?string $userAgent, ?string $successMessage): void
    {
        $wire = self::payload();
        $wire['requestId'] = $requestId;
        $wire['userAgent'] = $userAgent;
        $wire['successMessage'] = $successMessage;
        $ask = UserPushSubscribeSignalData::fromArray($wire);
        self::assertSame($wire, $ask->toArray());
        self::assertSame($wire, UserPushSubscribeSignalData::fromJson($ask->toJson())->toArray());
    }

    /** @return iterable<string, array{string, mixed}> Invalid strict ids */
    public static function invalidIds(): iterable
    {
        foreach (['userId', 'subscriptionId'] as $key) {
            foreach ([0, -1, '7', false, 7.0, null] as $value) {
                yield $key . ':' . json_encode($value) => [$key, $value];
            }
        }
    }

    /**
     * @param string $key Id field
     * @param mixed $value Malformed id
     */
    #[DataProvider('invalidIds')]
    public function testIdsArePositiveIntegersWithoutCoercion(string $key, mixed $value): void
    {
        $wire = self::payload();
        $wire[$key] = $value;
        $this->expectException(InvalidFormatException::class);
        UserPushSubscribeSignalData::fromArray($wire);
    }

    /** @return iterable<string, array{string, bool}> Required payload fields */
    public static function requiredFields(): iterable
    {
        foreach (['userId', 'subscriptionId', 'endpoint', 'p256dh', 'auth', 'replySignal', 'acceptKey', 'action'] as $key) {
            yield $key . ':missing' => [$key, true];
            yield $key . ':wrong type' => [$key, false];
        }
    }

    /**
     * @param string $key Required field
     * @param bool $missing Whether to omit it rather than supply an invalid type
     */
    #[DataProvider('requiredFields')]
    public function testRequiredFieldsCannotBeOmittedOrMistyped(string $key, bool $missing): void
    {
        $wire = self::payload();
        if ($missing) {
            unset($wire[$key]);
        } else {
            $wire[$key] = [];
        }
        $this->expectException(InvalidFormatException::class);
        UserPushSubscribeSignalData::fromArray($wire);
    }

    /** @return iterable<string, array{string}> Required nonempty subscription attributes */
    public static function emptyAttributes(): iterable
    {
        yield 'endpoint' => ['endpoint'];
        yield 'public key' => ['p256dh'];
        yield 'auth' => ['auth'];
    }

    /** @param string $key Empty required subscription attribute */
    #[DataProvider('emptyAttributes')]
    public function testEmptyEndpointAndKeysAreRefused(string $key): void
    {
        $wire = self::payload();
        $wire[$key] = '';
        $this->expectException(InvalidFormatException::class);
        UserPushSubscribeSignalData::fromArray($wire);
    }

    /** @return array<string, int|string|null> Complete accepted handover */
    private static function payload(): array
    {
        return [
            'userId' => 7,
            'subscriptionId' => 19,
            'endpoint' => 'https://push.example/device',
            'p256dh' => 'public-key',
            'auth' => 'auth-secret',
            'userAgent' => null,
            'replySignal' => HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE,
            'acceptKey' => 'accept-7',
            'requestId' => null,
            'action' => PushSubscriptionAction::SUBSCRIBE,
            'successMessage' => null,
        ];
    }
}

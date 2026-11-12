<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Users\DTO\UserPushReleaseSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserPushReleaseSignalDataTest extends TestCase
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
        $ask = UserPushReleaseSignalData::fromArray($wire);
        self::assertSame($wire, $ask->toArray());
        self::assertSame($wire, UserPushReleaseSignalData::fromJson($ask->toJson())->toArray());
    }

    /** @return iterable<string, array{string, mixed}> Invalid strict ids */
    public static function invalidIds(): iterable
    {
        foreach (['userId', 'subscriptionId', 'subscriberUserId'] as $key) {
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
        UserPushReleaseSignalData::fromArray($wire);
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
        UserPushReleaseSignalData::fromArray($wire);
    }

    /** @return array<string, int|string|null> Complete release ask as the library sends it */
    private static function payload(): array
    {
        return [
            'userId' => 7,
            'subscriptionId' => 19,
            'endpoint' => 'https://push.example/device',
            'subscriberUserId' => 8,
            'p256dh' => 'public-key',
            'auth' => 'auth-secret',
            'userAgent' => null,
            'replySignal' => HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE,
            'acceptKey' => 'accept-8',
            'requestId' => null,
            'action' => PushSubscriptionAction::SUBSCRIBE,
            'successMessage' => null,
        ];
    }
}

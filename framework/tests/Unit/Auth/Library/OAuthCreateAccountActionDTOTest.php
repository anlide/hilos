<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Library;

use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthCreateAccountActionDTOTest extends TestCase
{
    public function testProviderMayBeAbsentAndTheTokenRemainsTheOnlySecret(): void
    {
        $dto = OAuthCreateAccountActionDTO::fromArray(['accountToken' => 'signed-token']);

        self::assertNull($dto->provider);
        self::assertArrayNotHasKey('provider', $dto->toArray());
        self::assertSame(['accountToken'], OAuthCreateAccountActionDTO::SECRET_FIELDS);
        self::assertSame('hilos_oauth_create_account', $dto->getAction());
        self::assertSame($dto->toArray(), OAuthCreateAccountActionDTO::fromArray($dto->toArray())->toArray());
    }

    /** @return list<array{string}> Known, unknown, and empty hints are words only */
    public static function providerKeys(): array
    {
        return [['oauth:github'], ['unknown-provider'], ['']];
    }

    /** @param string $provider Provider hint, not a capability */
    #[DataProvider('providerKeys')]
    public function testRoundTripsAStringProviderWithoutInterpretingIt(string $provider): void
    {
        $dto = OAuthCreateAccountActionDTO::fromArray([
            'accountToken' => 'signed-token',
            'provider' => $provider,
            'acceptedRevisions' => ['terms' => 'revision-1'],
            'themePick' => 'dark',
        ]);

        self::assertSame($provider, $dto->provider);
        self::assertSame($provider, $dto->toArray()['provider']);
        self::assertSame('signed-token', $dto->accountToken);
        self::assertSame($dto->toArray(), OAuthCreateAccountActionDTO::fromArray($dto->toArray())->toArray());
    }

    /** @return list<array{mixed}> Present providers must be strings */
    public static function malformedProviders(): array
    {
        return [[null], [false], [12], [[]]];
    }

    /** @param mixed $provider Mistyped hint */
    #[DataProvider('malformedProviders')]
    public function testRefusesAMistypedProvider(mixed $provider): void
    {
        self::expectException(InvalidFormatException::class);
        self::expectExceptionMessage('provider');

        OAuthCreateAccountActionDTO::fromArray(['accountToken' => 'token', 'provider' => $provider]);
    }

    public function testAProviderCannotReplaceTheRequiredToken(): void
    {
        self::expectException(InvalidFormatException::class);
        self::expectExceptionMessage('accountToken');

        OAuthCreateAccountActionDTO::fromArray(['provider' => 'oauth:github']);
    }
}

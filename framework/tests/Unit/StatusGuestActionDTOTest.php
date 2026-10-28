<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\ThrottleEmailIdentityInterface;
use Hilos\Core\Analytics\SecretPayloadMask;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos as HilosFacade;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guest payload validation before throttling, and secret declarations without production routes. */
final class StatusGuestActionDTOTest extends TestCase
{
    public function testSubscribeNormalizesBeforeExposingTheThrottleIdentityAndRoundtrips(): void
    {
        $dto = StatusSubscribeActionDTO::fromArray(['email' => '  Reader+Alerts@Example.TEST  ']);

        $this->assertInstanceOf(ThrottleEmailIdentityInterface::class, $dto);
        $this->assertSame('status_subscribe', $dto->getAction());
        $this->assertSame('reader+alerts@example.test', $dto->email);
        $this->assertSame($dto->email, $dto->throttleEmail());
        $this->assertSame(['email' => 'reader+alerts@example.test'], $dto->toArray());
        $this->assertEquals($dto, StatusSubscribeActionDTO::fromArray($dto->toArray()));
        $this->assertEquals($dto, StatusSubscribeActionDTO::fromJson($dto->toJson()));
        $this->assertEquals($dto, new StatusSubscribeActionDTO(' Reader+Alerts@Example.TEST '));
    }

    public function testAnAddressAtTheLengthLimitIsAllowedAfterTrimming(): void
    {
        $email = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61);
        $this->assertSame(254, strlen($email));
        $this->assertSame($email, (new StatusSubscribeActionDTO(' ' . $email . ' '))->email);
    }

    public function testAnOversizeAddressIsRefusedWithoutEchoingIt(): void
    {
        $email = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 62);
        $this->assertSame(255, strlen($email));
        try {
            StatusSubscribeActionDTO::fromArray(['email' => $email]);
            $this->fail('Oversize address was accepted');
        } catch (ValueTooLongException $exception) {
            $this->assertSame('Email address is too long', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Broken required email payloads */
    public static function invalidEmails(): iterable
    {
        yield 'absent' => [[]];
        yield 'null' => [['email' => null]];
        yield 'integer' => [['email' => 123]];
        yield 'boolean' => [['email' => false]];
        yield 'array' => [['email' => ['reader@example.test']]];
        yield 'nested data' => [['data' => ['email' => 'reader@example.test']]];
        yield 'empty' => [['email' => '']];
        yield 'whitespace' => [['email' => '  ']];
        yield 'malformed' => [['email' => 'private-address@']];
        yield 'display name' => [['email' => 'Reader <reader@example.test>']];
        yield 'header injection' => [['email' => "reader@example.test\r\nBcc: other@example.test"]];
    }

    #[DataProvider('invalidEmails')]
    public function testSubscribeRefusesMalformedAndMistypedPayloads(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        StatusSubscribeActionDTO::fromArray($payload);
    }

    /** @return iterable<string, array{class-string<ActionPayloadDTO>, string}> Exact guest link actions */
    public static function tokenActions(): iterable
    {
        yield 'confirm' => [StatusConfirmSubscriptionActionDTO::class, 'status_subscription_confirm'];
        yield 'preview' => [StatusUnsubscribePreviewActionDTO::class, 'status_unsubscribe_preview'];
        yield 'unsubscribe' => [StatusUnsubscribeActionDTO::class, 'status_unsubscribe'];
    }

    #[DataProvider('tokenActions')]
    public function testTokenPayloadKeepsItsExactActionAndRoundtrips(string $class, string $action): void
    {
        $token = str_repeat('aB09', 16);
        $dto = $class::fromArray(['token' => $token]);

        $this->assertNotInstanceOf(ThrottleEmailIdentityInterface::class, $dto);
        $this->assertSame($action, $dto->getAction());
        $this->assertSame(['token' => $token], $dto->toArray());
        $this->assertEquals($dto, $class::fromArray($dto->toArray()));
        $this->assertEquals($dto, $class::fromJson($dto->toJson()));
    }

    /** @return iterable<string, array{class-string<ActionPayloadDTO>, array<string, mixed>}> Broken token payloads */
    public static function invalidTokens(): iterable
    {
        $payloads = [
            'absent' => [],
            'null' => ['token' => null],
            'number' => ['token' => 123],
            'boolean' => ['token' => true],
            'array' => ['token' => [str_repeat('a', 64)]],
            'nested data' => ['data' => ['token' => str_repeat('a', 64)]],
            'empty' => ['token' => ''],
            'short' => ['token' => str_repeat('a', 63)],
            'long' => ['token' => str_repeat('a', 65)],
            'not hex' => ['token' => str_repeat('g', 64)],
            'leading whitespace' => ['token' => ' ' . str_repeat('a', 64)],
            'trailing newline' => ['token' => str_repeat('a', 64) . "\n"],
        ];
        foreach (self::tokenActions() as $name => [$class]) {
            foreach ($payloads as $case => $payload) {
                yield $name . ': ' . $case => [$class, $payload];
            }
        }
    }

    #[DataProvider('invalidTokens')]
    public function testTokenReaderRefusesWrongShapeAndFormat(string $class, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($payload);
    }

    #[DataProvider('tokenActions')]
    public function testDirectTokenConstructionAlsoValidatesWithoutEchoingSecrets(string $class): void
    {
        $token = str_repeat('private-link-', 6);
        try {
            new $class($token);
            $this->fail('Malformed token was accepted');
        } catch (ValidationException $exception) {
            $this->assertSame('Invalid subscription link', $exception->getMessage());
        }
    }

    public function testEachConcreteDtoDeclaresAndMasksItsOnlyFieldThroughTheRouter(): void
    {
        $router = new StatusGuestSecretTestRouter();
        $dtos = [new StatusSubscribeActionDTO('private@example.test')];
        foreach (self::tokenActions() as [$class]) {
            $dtos[] = new $class(str_repeat('a', 64));
        }
        foreach ($dtos as $dto) {
            $key = $dto instanceof StatusSubscribeActionDTO ? 'email' : 'token';
            $fields = $router->actionSecretFields($dto->getAction());
            $this->assertSame([$key], $fields);
            $this->assertSame([$key => SecretPayloadMask::MASK], SecretPayloadMask::apply($dto->toArray(), $fields));
            $this->assertSame(
                ['action' => $dto->getAction(), 'data' => [$key => SecretPayloadMask::MASK]],
                SecretPayloadMask::apply(['action' => $dto->getAction(), 'data' => $dto->toArray()], $fields),
            );
        }
    }
}

final class StatusGuestSecretTestPage extends AbstractPage
{
    public const array ACTIONS = [
        'status_subscribe' => StatusSubscribeActionDTO::class,
        'status_subscription_confirm' => StatusConfirmSubscriptionActionDTO::class,
        'status_unsubscribe_preview' => StatusUnsubscribePreviewActionDTO::class,
        'status_unsubscribe' => StatusUnsubscribeActionDTO::class,
    ];
}

abstract class StatusGuestSecretTestHilos extends HilosFacade
{
    public const array PAGES = ['status_guest_secrets' => StatusGuestSecretTestPage::class];
}

final class StatusGuestSecretTestRouter extends SignalRouter
{
    /** @return string Facade with guest actions declared only for this masking test */
    protected function hilosClass(): string
    {
        return StatusGuestSecretTestHilos::class;
    }
}

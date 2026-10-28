<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\ThrottleGate;
use Hilos\Auth\Throttle\ThrottleIdentity;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Core\Router\DTO\UnknownActionPayloadDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use PHPUnit\Framework\TestCase;

final class ThrottleEmailIdentityTest extends TestCase
{
    public function testEmailIdentityIsADomainSeparatedSha256DigestOfTheNormalizedAddress(): void
    {
        $email = 'reader@example.test';
        $identity = ThrottleIdentity::forEmail($email);

        $this->assertSame(hash('sha256', 'email:' . $email), $identity);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $identity);
        $this->assertStringNotContainsString($email, $identity);
        $this->assertNotSame(ThrottleIdentity::forSession($email), $identity);
        $this->assertNotSame(ThrottleIdentity::forEmail('another@example.test'), $identity);
        $this->assertSame(
            $identity,
            ThrottleIdentity::forEmail((new StatusSubscribeActionDTO(' Reader@Example.TEST '))->throttleEmail()),
        );
        $this->assertSame(hash('sha256', $email), ThrottleIdentity::forSession($email));
        $this->assertNull(ThrottleIdentity::forSession(''));
    }

    public function testThreeArgumentCallAndNonOptedInPayloadKeepExactlyTheOldChecks(): void
    {
        $gate = new ThrottleGate();
        $dto = new StatusSubscribeActionDTO('reader@example.test');
        $data = new WebSocketActionSignalDTO(
            acceptKey: 'accept',
            action: $dto->getAction(),
            data: $dto->toArray(),
            clientIp: '198.51.100.1',
            sessionIdentity: 'session-digest',
        );
        $source = new SignalSource(SignalSource::AGENT, 'host', 'instance');
        $legacy = $gate->checksFor($data, 'request', $source);
        $untyped = $gate->checksFor($data, 'request', $source, new UnknownActionPayloadDTO($dto->getAction(), $dto->toArray()));

        $this->assertCount(2, $legacy);
        $this->assertEquals($legacy, $untyped);
        $this->assertSame([ThrottleScope::IP, ThrottleScope::SESSION], array_column($legacy, 'scope'));
        $this->assertSame(['198.51.100.1', 'session-digest'], array_column($legacy, 'identity'));
        foreach ($legacy as $check) {
            $this->assertSame('host', $check->agentType);
            $this->assertSame('instance', $check->agentIndex);
        }

        foreach ([StatusConfirmSubscriptionActionDTO::class, StatusUnsubscribePreviewActionDTO::class, StatusUnsubscribeActionDTO::class] as $class) {
            $tokenDto = new $class(str_repeat('a', 64));
            $this->assertEquals($legacy, $gate->checksFor($data, 'request', $source, $tokenDto));
        }
    }

    public function testEmailCanBeCountedWithoutInventingAnIpOrSessionIdentity(): void
    {
        $gate = new ThrottleGate();
        $dto = new StatusSubscribeActionDTO('reader@example.test');
        $data = new WebSocketActionSignalDTO('accept', $dto->getAction());
        $source = new SignalSource(SignalSource::AGENT, 'host');

        $this->assertSame([], $gate->checksFor($data, 'request', $source));
        $checks = $gate->checksFor($data, 'request', $source, $dto);
        $this->assertCount(1, $checks);
        $this->assertSame(ThrottleScope::EMAIL, $checks[0]->scope);
        $this->assertSame(ThrottleIdentity::forEmail($dto->email), $checks[0]->identity);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Sms;

use Hilos\Auth\Verification\SmsVerificationDeliverer;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;
use Hilos\Sms\Template\SmsTemplateCatalogConstants;
use Hilos\Sms\Template\SmsVerificationCodeTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SMS verification deliverer that texts a code (HIL-285).
 *
 * {@see SmsVerificationDeliverer} maps an SMS {@see VerificationType} to its `auth.*` template
 * key and hands the code to {@see Hilos::$sms} as a raw-send - the code travels only in the
 * queued {@see SmsSendSignalData} params, never through a log. A non-SMS (email) type has no
 * SMS template and is a silent no-op.
 */
final class SmsVerificationDelivererTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?SignalRouter $previousRouter = null;
    private ?HilosSmsSender $previousSms = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousSms = Hilos::$sms;
        putenv('SMS_WORKER_COUNT');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$sms = new HilosSmsSender();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$sms = $this->previousSms;
        putenv('SMS_WORKER_COUNT');
        parent::tearDown();
    }

    public function testSmsLoginTypeQueuesTemplatedRawSend(): void
    {
        $router = new SmsVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;

        new SmsVerificationDeliverer()->deliver('+15551234567', VerificationType::SMS_LOGIN, '654321');

        self::assertCount(1, $router->captured);
        $signal = $router->captured[0];
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal['type']);
        self::assertSame(HilosSignalConstants::HILOS_SMS_SEND, $signal['name']);

        $payload = $signal['data']->data;
        self::assertInstanceOf(SmsSendSignalData::class, $payload);
        self::assertSame('+15551234567', $payload->to);
        self::assertSame(HilosSmsSender::shardKeyForNumber('+15551234567'), $payload->shardKey);
        self::assertSame(SmsTemplateCatalogConstants::AUTH_SMS_LOGIN, $payload->templateKey);
        self::assertSame([SmsVerificationCodeTemplate::PARAM_CODE => '654321'], $payload->params);
        self::assertNull($payload->text);
    }

    public function testSmsAddTypeMapsToItsTemplateKey(): void
    {
        $router = new SmsVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;

        new SmsVerificationDeliverer()->deliver('+15551234567', VerificationType::SMS_ADD, '111222');

        self::assertCount(1, $router->captured);
        self::assertSame(
            SmsTemplateCatalogConstants::AUTH_SMS_ADD,
            $router->captured[0]['data']->data->templateKey,
        );
    }

    public function testEmailTypeIsNoOp(): void
    {
        $router = new SmsVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;

        new SmsVerificationDeliverer()->deliver('user@example.com', VerificationType::REGISTER_CONFIRM, '000000');

        self::assertCount(0, $router->captured);
    }
}

/**
 * Signal router double that records queued signals instead of routing them.
 */
final class SmsVerificationDelivererTestSignalRouter extends SignalRouter
{
    /** @var list<array{type: string, name: string, data: AgentSignalData}> */
    public array $captured = [];

    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        $this->captured[] = [
            'type' => $signalType->getType(),
            'name' => $signalName->getName(),
            'data' => $signalData,
        ];
    }
}

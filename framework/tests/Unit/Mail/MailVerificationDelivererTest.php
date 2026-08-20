<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Auth\Verification\MailVerificationDeliverer;
use Hilos\Auth\Verification\VerificationDeliverable;
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
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\MagicLinkMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use PHPUnit\Framework\TestCase;

/**
 * Tests the framework-default verification deliverer that emails the code (HIL-197 SLICE 7).
 *
 * {@see MailVerificationDeliverer} maps an email {@see VerificationType} to its `auth.*`
 * template key and hands the code to {@see Hilos::$mail} as a raw-send — the code travels
 * only in the queued {@see MailSendSignalData} params, never through a log. A non-email
 * (SMS) type has no email template and is a silent no-op.
 */
final class MailVerificationDelivererTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?SignalRouter $previousRouter = null;
    private ?HilosMailer $previousMail = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousMail = Hilos::$mail;
        putenv('MAIL_WORKER_COUNT');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$mail = new HilosMailer();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$mail = $this->previousMail;
        putenv('MAIL_WORKER_COUNT');
        parent::tearDown();
    }

    public function testEmailCodeTypeQueuesTemplatedRawSend(): void
    {
        $router = new MailVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;

        new MailVerificationDeliverer()->deliver(
            'user@example.com',
            VerificationType::REGISTER_CONFIRM,
            VerificationDeliverable::code('123456'),
        );

        self::assertCount(1, $router->captured);
        $signal = $router->captured[0];
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal['type']);
        self::assertSame(HilosSignalConstants::HILOS_MAIL_SEND, $signal['name']);

        $payload = $signal['data']->data;
        self::assertInstanceOf(MailSendSignalData::class, $payload);
        self::assertSame('user@example.com', $payload->to);
        self::assertSame(HilosMailer::shardKeyForAddress('user@example.com'), $payload->shardKey);
        self::assertSame(MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM, $payload->templateKey);
        self::assertSame([AbstractVerificationCodeMailTemplate::PARAM_CODE => '123456'], $payload->params);
        self::assertNull($payload->subject);
        self::assertNull($payload->text);
    }

    public function testEveryEmailTypeMapsToItsTemplateKey(): void
    {
        $router = new MailVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;
        $deliverer = new MailVerificationDeliverer();

        $expected = [
            VerificationType::REGISTER_CONFIRM => MailTemplateCatalogConstants::AUTH_REGISTER_CONFIRM,
            VerificationType::PASSWORD_RESET => MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
            VerificationType::EMAIL_CHANGE => MailTemplateCatalogConstants::AUTH_EMAIL_CHANGE,
            VerificationType::EMAIL_ADD => MailTemplateCatalogConstants::AUTH_EMAIL_ADD,
        ];

        foreach ($expected as $type => $templateKey) {
            $deliverer->deliver('user@example.com', $type, VerificationDeliverable::code('000000'));
        }

        self::assertSame(array_values($expected), array_map(
            static fn (array $signal): ?string => $signal['data']->data->templateKey,
            $router->captured,
        ));
    }

    public function testMagicLinkForwardsBothHalvesOfTheLetter(): void
    {
        $router = new MailVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;

        new MailVerificationDeliverer()->deliver(
            'user@example.com',
            VerificationType::MAGIC_LINK,
            VerificationDeliverable::magicLink('https://app.example/auth/magic?t=deadbeef', '135790'),
        );

        self::assertCount(1, $router->captured);
        $payload = $router->captured[0]['data']->data;
        self::assertInstanceOf(MailSendSignalData::class, $payload);
        self::assertSame(MailTemplateCatalogConstants::AUTH_MAGIC_LINK, $payload->templateKey);
        self::assertSame(
            [
                MagicLinkMailTemplate::PARAM_LINK => 'https://app.example/auth/magic?t=deadbeef',
                MagicLinkMailTemplate::PARAM_CODE => '135790',
            ],
            $payload->params,
        );
    }

    public function testSmsTypeIsNoOp(): void
    {
        $router = new MailVerificationDelivererTestSignalRouter();
        Hilos::$sr = $router;

        new MailVerificationDeliverer()->deliver(
            '+15551234567',
            VerificationType::SMS_LOGIN,
            VerificationDeliverable::code('654321'),
        );

        self::assertCount(0, $router->captured);
    }
}

/**
 * Signal router double that records queued signals instead of routing them.
 */
final class MailVerificationDelivererTestSignalRouter extends SignalRouter
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

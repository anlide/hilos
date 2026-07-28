<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the mail send seam: the raw-send agent signal and the address shard key (HIL-197).
 *
 * {@see HilosMailer::send()} never sends in place — it queues an AGENT_SIGNAL named
 * {@see HilosSignalConstants::HILOS_MAIL_SEND} carrying a {@see MailSendSignalData}. An
 * inline {@see EmailMessage} is sharded and wrapped; a ready payload keeps its own shard
 * key. {@see HilosMailer::shardKeyForAddress()} is stable and address-normalized so a
 * recipient always lands on one pool instance.
 */
final class HilosMailerTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        putenv('MAIL_WORKER_COUNT');
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        putenv('MAIL_WORKER_COUNT');
        parent::tearDown();
    }

    public function testShardKeyForAddressIsStableAndAddressNormalized(): void
    {
        putenv('MAIL_WORKER_COUNT=8');

        $canonical = HilosMailer::shardKeyForAddress('user@example.com');

        self::assertSame($canonical, HilosMailer::shardKeyForAddress('user@example.com'));
        self::assertSame($canonical, HilosMailer::shardKeyForAddress('  User@Example.COM '));
        self::assertGreaterThanOrEqual(1, $canonical);
        self::assertLessThanOrEqual(8, $canonical);
    }

    public function testShardKeyDefaultsToSinglePool(): void
    {
        self::assertSame(1, HilosMailer::shardKeyForAddress('a@example.com'));
        self::assertSame(1, HilosMailer::shardKeyForAddress('b@example.com'));
    }

    public function testSendInlineEmailQueuesRawSendSignal(): void
    {
        putenv('MAIL_WORKER_COUNT=4');
        $router = new HilosMailerTestSignalRouter();
        Hilos::$sr = $router;

        (new HilosMailer())->send(new EmailMessage(
            to: 'user@example.com',
            subject: 'Hello',
            text: 'Body text',
            html: '<p>Body</p>',
        ));

        self::assertCount(1, $router->captured);
        $signal = $router->captured[0];
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal['type']);
        self::assertSame(HilosSignalConstants::HILOS_MAIL_SEND, $signal['name']);

        $data = $signal['data'];
        self::assertInstanceOf(AgentSignalData::class, $data);
        $payload = $data->data;
        self::assertInstanceOf(MailSendSignalData::class, $payload);
        self::assertSame('user@example.com', $payload->to);
        self::assertSame(HilosMailer::shardKeyForAddress('user@example.com'), $payload->shardKey);
        self::assertSame('Hello', $payload->subject);
        self::assertSame('Body text', $payload->text);
        self::assertSame('<p>Body</p>', $payload->html);
        self::assertNull($payload->templateKey);
    }

    public function testSendPreformedSignalPreservesShardKeyAndTemplate(): void
    {
        putenv('MAIL_WORKER_COUNT=4');
        $router = new HilosMailerTestSignalRouter();
        Hilos::$sr = $router;

        (new HilosMailer())->send(new MailSendSignalData(
            to: 'code@example.com',
            shardKey: 3,
            templateKey: 'auth.magic_link',
            params: ['link' => 'https://example.test/magic'],
        ));

        self::assertCount(1, $router->captured);
        $payload = $router->captured[0]['data']->data;
        self::assertInstanceOf(MailSendSignalData::class, $payload);
        self::assertSame(3, $payload->shardKey);
        self::assertSame('auth.magic_link', $payload->templateKey);
        self::assertSame(['link' => 'https://example.test/magic'], $payload->params);
    }

    public function testSendWithoutRouterIsNoOp(): void
    {
        Hilos::$sr = null;

        $this->expectNotToPerformAssertions();
        (new HilosMailer())->send(new EmailMessage(to: 'a@example.com', subject: 's', text: 't'));
    }
}

/**
 * Signal router double that records queued signals instead of routing them.
 */
final class HilosMailerTestSignalRouter extends SignalRouter
{
    /** @var list<array{type: string, name: string, data: SignalDataInterface}> */
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

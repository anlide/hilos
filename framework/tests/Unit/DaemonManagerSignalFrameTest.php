<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * What the daemon does with a signal it cannot turn into a frame (HIL-583, a tail of
 * the HIL-543 review).
 *
 * A payload that json_encode refuses leaves the node a choice of two bad answers, and the
 * one it takes is worth holding down: the frame is not sent, and a line naming the signal
 * is written instead. Without the line a client would simply stop receiving that signal,
 * and nothing in the process would say why.
 *
 * The encoder is driven directly through a subclass rather than through either of its
 * two callers. Both of them answer null in the same three lines, while reaching the
 * broadcast one asks for a stand-in WebSocketServer — a concrete class holding a socket,
 * not an interface — which is the obvious reason this path had no test until now.
 *
 * The refusal is a broken UTF-8 sequence, the one refusal of the three (NAN and recursion
 * being the others) that arrives from outside the process: off a socket, out of the
 * database, from somebody else's client.
 */
final class DaemonManagerSignalFrameTest extends TestCase
{
    /** Signal name carried by every frame here; also what the written line is searched for */
    private const string SIGNAL_NAME = 'unit-frame-drop';

    /** Temporary main log file the assertions read the written line back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-signal-frame-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * The name is asserted separately from the wording because it is the only thing
     * telling one dropped frame from another; a line without it costs the reader the
     * whole search.
     */
    public function testAPayloadJsonRefusesIsDroppedAndWrittenWithItsSignalName(): void
    {
        $manager = new DaemonManagerSignalFrameTestManager();

        $this->assertNull($manager->encodeFrame($this->signal(['s' => "bad-\xB1\x31"])));

        $written = $this->written();
        $this->assertStringContainsString('Signal frame dropped', $written);
        $this->assertStringContainsString(self::SIGNAL_NAME, $written);
    }

    /**
     * Without this case the file above stays green on an encoder broken to return null
     * always, which is to say it would hold nothing at all.
     */
    public function testAnEncodablePayloadComesBackAsTheFrameAndIsNotWritten(): void
    {
        $manager = new DaemonManagerSignalFrameTestManager();

        $frame = $manager->encodeFrame($this->signal(['s' => 'ok']));

        $this->assertSame('{"type":"unit-frame-drop","data":{"s":"ok"}}', $frame);
        $this->assertSame('', $this->written());
    }

    /**
     * Builds the one signal shape every case here shares apart from its payload.
     *
     * @param array<string, mixed> $payload Payload the frame is built from
     * @return SignalDTO Signal carrying that payload
     */
    private function signal(array $payload): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(self::SIGNAL_NAME),
            new SignalData($payload),
        );
    }

    /**
     * Reads back whatever the encoder wrote to the temporary log.
     *
     * @return string Log contents, empty when the encoder stayed silent
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Daemon manager offering the encoder to the test; nothing else about it is exercised.
 */
final class DaemonManagerSignalFrameTestManager extends DaemonManager
{
    /**
     * @param SignalDTO $signal Signal to serialize
     * @return ?string Frame JSON, or null when the payload cannot be encoded
     */
    public function encodeFrame(SignalDTO $signal): ?string
    {
        return $this->encodeSignalFrame($signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DaemonManagerSignalFrameTestAgentManagerDaemon();
    }
}

final class DaemonManagerSignalFrameTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; the test starts no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

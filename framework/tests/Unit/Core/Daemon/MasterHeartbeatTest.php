<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use ErrorException;
use Hilos\Core\Daemon\MasterHeartbeat;
use Hilos\Core\Exception\ProcessException;
use PHPUnit\Framework\TestCase;

final class MasterHeartbeatTest extends TestCase
{
    public function testDirectLaunchDoesNotOpenAChannel(): void
    {
        self::assertNull(MasterHeartbeat::fromArguments(['daemon.php']));
        self::assertNull(MasterHeartbeat::fromArguments(['worker.php', '--watchdog-heartbeat=3']));
        self::assertSame(3, MasterHeartbeat::DESCRIPTOR);
        self::assertSame("\x01", MasterHeartbeat::MARK);
    }

    public function testCompletedPassHasItsOwnIntervalWithoutCatchUp(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($reader, false);
        $heartbeat = new MasterHeartbeat($writer, 100.0);
        try {
            self::assertSame(100.0, $heartbeat->nextMarkAt());
            $heartbeat->onPassCompleted(100.0);
            self::assertSame("\x01", fread($reader, 4096));
            self::assertSame(110.0, $heartbeat->nextMarkAt());
            $heartbeat->onPassCompleted(109.999);
            self::assertSame('', fread($reader, 4096));
            $heartbeat->onPassCompleted(150.0);
            self::assertSame("\x01", fread($reader, 4096));
            self::assertSame(160.0, $heartbeat->nextMarkAt());
        } finally {
            $heartbeat->close();
            fclose($reader);
        }
    }

    public function testFullPipeDropsAnAttemptWithoutGrowingAQueue(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($reader, false);
        $heartbeat = new MasterHeartbeat($writer, 0.0);
        try {
            $written = null;
            for ($attempt = 0; $attempt < 512; ++$attempt) {
                $written = fwrite($writer, str_repeat('x', 8192));
                if ($written === 0) {
                    break;
                }
            }
            self::assertSame(0, $written, 'bounded setup must reach nonblocking backpressure');
            $heartbeat->onPassCompleted(0.0);
            self::assertSame(10.0, $heartbeat->nextMarkAt());
            self::assertStringNotContainsString(MasterHeartbeat::MARK, stream_get_contents($reader));
            $heartbeat->onPassCompleted(9.0);
            self::assertSame('', stream_get_contents($reader));
            $heartbeat->onPassCompleted(10.0);
            self::assertSame("\x01", stream_get_contents($reader));
            self::assertSame(20.0, $heartbeat->nextMarkAt());
        } finally {
            $heartbeat->close();
            fclose($reader);
        }
    }

    public function testFailedWriterRefusesAndCloseIsIdempotent(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $heartbeat = new MasterHeartbeat($writer, 0.0);
        fclose($reader);
        try {
            set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                try {
                    $heartbeat->onPassCompleted(0.0);
                    self::fail('a broken declared pipe must refuse without suppression');
                } catch (ProcessException $failure) {
                    self::assertInstanceOf(ErrorException::class, $failure->getPrevious());
                    self::assertSame(0.0, $heartbeat->nextMarkAt());
                }
            } finally {
                restore_error_handler();
            }
            fclose($writer);
            try {
                $heartbeat->onPassCompleted(0.0);
                self::fail('a lost declared writer must refuse');
            } catch (ProcessException $failure) {
                self::assertStringContainsString('no longer open', $failure->getMessage());
            }
            $heartbeat->close();
            $heartbeat->close();
            self::assertNull($heartbeat->nextMarkAt());
            $heartbeat->onPassCompleted(100.0);
        } finally {
            $heartbeat->close();
        }
    }
}

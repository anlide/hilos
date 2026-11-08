<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Socket\Client\AbstractClient;

/** Minimal protocol owner for native TLS application-byte and drain assertions. */
final class TlsTestClient extends AbstractClient
{
    public string $received = '';

    /** @var list<int> Actual input portion sizes */
    public array $portions = [];

    public int $drains = 0;

    public int $ticks = 0;

    /** @param string $bytes New application bytes */
    public function queue(string $bytes): void
    {
        $this->queueWrite($bytes);
    }

    /** @return string Original bytes still awaiting transport completion */
    public function pendingOutbound(): string
    {
        return $this->writeBuffer;
    }

    /** Records bounded owner invocations. */
    public function onTick(): void
    {
        ++$this->ticks;
    }

    /** Collects actual input without introducing any parser-specific continuation. */
    protected function processReadBuffer(): void
    {
        $this->portions[] = strlen($this->readBuffer);
        $this->received .= $this->readBuffer;
        $this->readBuffer = '';
    }

    /** Counts the actual native-drained application output. */
    protected function onAfterOutboundDrained(): void
    {
        ++$this->drains;
    }

    /** No additional close side effects. */
    protected function onClose(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Core\Daemon\DaemonOutputQuote;
use PHPUnit\Framework\TestCase;

final class DaemonOutputQuoteTest extends TestCase
{
    private string $tempDir;
    private string $logFile;
    private string $errorLogFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/hilos-daemon-quote-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->logFile = $this->tempDir . '/daemon.log';
        $this->errorLogFile = $this->tempDir . '/daemon-error.log';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testTextWrittenBeforeTheMarkIsNotQuoted(): void
    {
        $stdoutRaw = $this->tempDir . '/daemon-raw.log';
        file_put_contents($stdoutRaw, "Fatal error: Allowed memory size of 134217728 bytes exhausted\n");

        $quote = DaemonOutputQuote::markedAt($this->logFile, $this->errorLogFile);

        self::assertSame('(the daemon printed nothing)', $quote->render(2000));
    }

    public function testStdoutTwinIsQuotedWithItsFileName(): void
    {
        $quote = DaemonOutputQuote::markedAt($this->logFile, $this->errorLogFile);

        $stdoutRaw = $this->tempDir . '/daemon-raw.log';
        file_put_contents($stdoutRaw, "Fatal error: Uncaught Exception\n");

        self::assertSame('daemon-raw.log: Fatal error: Uncaught Exception', $quote->render(2000));
    }

    public function testJournalComesFirstAndTheBudgetIsSplit(): void
    {
        $quote = DaemonOutputQuote::markedAt($this->logFile, $this->errorLogFile);

        $stdoutRaw = $this->tempDir . '/daemon-raw.log';
        $stderrRaw = $this->tempDir . '/daemon-error-raw.log';

        file_put_contents($this->errorLogFile, "11111111112222222222\n");
        file_put_contents($stdoutRaw, "aaaaaaaaaabbbbbbbbbb\n");
        file_put_contents($stderrRaw, "xxxxxxxxxyyyyyyyyyy\n");

        $expected = "daemon-error.log: 2222222222\n"
            . "daemon-raw.log: bbbbbbbbbb\n"
            . "daemon-error-raw.log: yyyyyyyyyy";

        self::assertSame($expected, $quote->render(30));
    }

    public function testIdenticalTextInBothRawTwinsIsQuotedOnce(): void
    {
        $quote = DaemonOutputQuote::markedAt($this->logFile, $this->errorLogFile);

        $stdoutRaw = $this->tempDir . '/daemon-raw.log';
        $stderrRaw = $this->tempDir . '/daemon-error-raw.log';

        $text = "Required environment values are missing: DB_HOST, DB_USER\n";
        file_put_contents($stdoutRaw, $text);
        file_put_contents($stderrRaw, $text);

        self::assertSame(
            'daemon-raw.log, daemon-error-raw.log: Required environment values are missing: DB_HOST, DB_USER',
            $quote->render(2000),
        );
    }

    public function testNothingAppendedSaysTheDaemonPrintedNothing(): void
    {
        $quote = DaemonOutputQuote::markedAt($this->logFile, $this->errorLogFile);

        self::assertSame('(the daemon printed nothing)', $quote->render(2000));
    }

    public function testNoAddressConfiguredSaysTheOutputIsHere(): void
    {
        $quote = DaemonOutputQuote::markedAt(null, null);

        self::assertSame('(the daemon printed straight to this log)', $quote->render(2000));
    }

    public function testAStreamShorterThanItsMarkIsQuotedFromTheStart(): void
    {
        $stdoutRaw = $this->tempDir . '/daemon-raw.log';
        file_put_contents($stdoutRaw, str_repeat('A', 100));

        $quote = DaemonOutputQuote::markedAt($this->logFile, $this->errorLogFile);

        file_put_contents($stdoutRaw, "New line after rot\n");

        self::assertSame('daemon-raw.log: New line after rot', $quote->render(2000));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}

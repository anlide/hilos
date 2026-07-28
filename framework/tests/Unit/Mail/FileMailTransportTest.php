<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailBusyException;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\FileMailTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests the .eml file transport used by dev/e2e in place of a real SMTP send (HIL-197).
 */
final class FileMailTransportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hilos-mail-test-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testWritesEmlArtifactAndReportsDelivered(): void
    {
        $transport = new FileMailTransport($this->dir, 'from@example.com', 'Sender');
        $transport->start(new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'Hello'), 1000.0);

        $this->assertFalse($transport->isBusy());
        $this->assertTrue($transport->hasResult());

        $outcome = $transport->consumeResult();
        $this->assertTrue($outcome->delivered);
        $this->assertNull($outcome->errorDetail);

        $files = glob($this->dir . '/*.eml') ?: [];
        $this->assertCount(1, $files);
        $written = file_get_contents($files[0]);
        $this->assertStringContainsString('To: user@example.com', (string)$written);
        $this->assertStringContainsString(base64_encode('Hello'), (string)$written);
    }

    public function testResultIsConsumedOnce(): void
    {
        $transport = new FileMailTransport($this->dir, 'from@example.com');
        $transport->start(new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'body'), 1000.0);
        $transport->consumeResult();

        $this->assertFalse($transport->hasResult());
        $this->expectException(MailResultUnavailableException::class);
        $transport->consumeResult();
    }

    public function testStartingBeforeConsumingResultThrowsBusy(): void
    {
        $transport = new FileMailTransport($this->dir, 'from@example.com');
        $transport->start(new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'body'), 1000.0);

        $this->expectException(MailBusyException::class);
        $transport->start(new EmailMessage(to: 'other@example.com', subject: 'Hi', text: 'body'), 1001.0);
    }

    public function testCloseDropsUnconsumedResult(): void
    {
        $transport = new FileMailTransport($this->dir, 'from@example.com');
        $transport->start(new EmailMessage(to: 'user@example.com', subject: 'Hi', text: 'body'), 1000.0);
        $transport->close();

        $this->assertFalse($transport->hasResult());
    }
}

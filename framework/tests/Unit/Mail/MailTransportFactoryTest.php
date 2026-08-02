<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Mail\FileMailTransport;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\MailTransportFactory;
use Hilos\Mail\SmtpMailTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the factory maps transport selection and host presence to the right driver (HIL-197).
 */
final class MailTransportFactoryTest extends TestCase
{
    public function testAutoSelectsFileTransportWithoutSmtpHost(): void
    {
        $transport = new MailTransportFactory()->create($this->config(transport: null, smtpHost: ''));

        $this->assertInstanceOf(FileMailTransport::class, $transport);
    }

    public function testAutoSelectsSmtpTransportWithSmtpHost(): void
    {
        $transport = new MailTransportFactory()->create($this->config(transport: null, smtpHost: 'mail.example.com'));

        $this->assertInstanceOf(SmtpMailTransport::class, $transport);
    }

    public function testExplicitFileSelectionWinsOverConfiguredHost(): void
    {
        $transport = new MailTransportFactory()->create($this->config(transport: 'file', smtpHost: 'mail.example.com'));

        $this->assertInstanceOf(FileMailTransport::class, $transport);
    }

    public function testExplicitSmtpSelectionWinsWithoutHost(): void
    {
        $transport = new MailTransportFactory()->create($this->config(transport: 'smtp', smtpHost: ''));

        $this->assertInstanceOf(SmtpMailTransport::class, $transport);
    }

    /**
     * Builds a config with the given selection and host, other fields fixed.
     *
     * @param ?string $transport Forced driver, or null to auto-select
     * @param string $smtpHost SMTP host
     * @return MailTransportConfig Config for the factory
     */
    private function config(?string $transport, string $smtpHost): MailTransportConfig
    {
        return new MailTransportConfig(
            fromAddress: 'from@example.com',
            fileDir: sys_get_temp_dir(),
            transport: $transport,
            smtpHost: $smtpHost,
        );
    }
}

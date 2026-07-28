<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Mail;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\Exception\MailConfigException;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\MailTransportFactory;
use Hilos\Mail\SmtpSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Tests that fromEnv() maps the MAIL_* environment values onto the transport recipe (HIL-197).
 *
 * The optional-secret keys collapse an empty value to null (auto driver, unauthenticated
 * relay, bare From address); MAIL_SMTP_SECURITY parses to the enum and rejects an unknown mode.
 */
final class MailTransportConfigTest extends TestCase
{
    private const array MAIL_KEYS = [
        'MAIL_TRANSPORT',
        'MAIL_SMTP_HOST',
        'MAIL_SMTP_PORT',
        'MAIL_SMTP_SECURITY',
        'MAIL_SMTP_USERNAME',
        'MAIL_SMTP_PASSWORD',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_TIMEOUT_MS',
        'MAIL_FILE_DIR',
    ];

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearMailEnv();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->clearMailEnv();
        parent::tearDown();
    }

    public function testCatalogDefaultsAutoSelectFileTransport(): void
    {
        $config = MailTransportConfig::fromEnv();

        self::assertNull($config->transport);
        self::assertSame('', $config->smtpHost);
        self::assertSame(587, $config->smtpPort);
        self::assertSame(SmtpSecurity::STARTTLS, $config->security);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame('', $config->fromAddress);
        self::assertNull($config->fromName);
        self::assertSame(10000, $config->timeoutMs);
        self::assertSame('', $config->fileDir);
    }

    public function testExplicitSmtpConfigurationIsResolved(): void
    {
        putenv('MAIL_TRANSPORT=smtp');
        putenv('MAIL_SMTP_HOST=smtp.example.com');
        putenv('MAIL_SMTP_PORT=465');
        putenv('MAIL_SMTP_SECURITY=tls');
        putenv('MAIL_SMTP_USERNAME=mailer');
        putenv('MAIL_SMTP_PASSWORD=secret');
        putenv('MAIL_FROM_ADDRESS=no-reply@example.com');
        putenv('MAIL_FROM_NAME=Example');
        putenv('MAIL_TIMEOUT_MS=5000');

        $config = MailTransportConfig::fromEnv();

        self::assertSame(MailTransportFactory::TRANSPORT_SMTP, $config->transport);
        self::assertSame('smtp.example.com', $config->smtpHost);
        self::assertSame(465, $config->smtpPort);
        self::assertSame(SmtpSecurity::TLS, $config->security);
        self::assertSame('mailer', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame('no-reply@example.com', $config->fromAddress);
        self::assertSame('Example', $config->fromName);
        self::assertSame(5000, $config->timeoutMs);
    }

    public function testUnknownSecurityModeThrows(): void
    {
        putenv('MAIL_SMTP_SECURITY=quantum');

        $this->expectException(MailConfigException::class);

        MailTransportConfig::fromEnv();
    }

    private function clearMailEnv(): void
    {
        foreach (self::MAIL_KEYS as $key) {
            putenv($key);
        }
    }
}

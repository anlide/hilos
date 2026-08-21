<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\WatchdogAlertMailer;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\FailedMailTransport;
use Hilos\Mail\FileMailTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests the two alerts the container watchdog mails, and the silence around them (HIL-617).
 *
 * The transport is a file transport standing in for the relay, which is the only reason both
 * occasions are checkable at all: the watchdog sends synchronously, outside any worker.
 * What the tests pin down is what an operator receives — a subject naming the node and the
 * incident, a body naming the reason — and, just as deliberately, what never travels: the
 * application error log beyond its last line, and any exception out of a failed send.
 */
final class WatchdogAlertMailerTest extends TestCase
{
    /** @var list<string> Env names this test writes and clears around itself */
    private const array WATCHDOG_KEYS = [
        'WATCHDOG_ALERT_SMTP_HOST',
        'WATCHDOG_ALERT_SMTP_PORT',
        'WATCHDOG_ALERT_SMTP_SECURITY',
        'WATCHDOG_ALERT_SMTP_USERNAME',
        'WATCHDOG_ALERT_SMTP_PASSWORD',
        'WATCHDOG_ALERT_FROM_ADDRESS',
        'WATCHDOG_ALERT_TO_ADDRESS',
        'WATCHDOG_ALERT_TIMEOUT_MS',
    ];

    /** @var string Recipient the alerts are addressed to in these tests */
    private const string RECIPIENT = 'ops@example.com';

    private string $dir;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hilos-watchdog-alert-' . getmypid() . '-' . uniqid();
        $this->clearWatchdogEnv();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->clearWatchdogEnv();
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testFailedStartAlertNamesTheRecipientTheCountAndTheReason(): void
    {
        putenv('WATCHDOG_ALERT_TO_ADDRESS=' . self::RECIPIENT);

        $this->mailer()->sendDaemonFailedStart(5, 1.5, "PHP Fatal error: no database\n");

        $written = $this->writtenAlert();
        self::assertStringContainsString('To: ' . self::RECIPIENT, $written);
        self::assertStringContainsString('[Hilos watchdog]', $written);
        self::assertStringContainsString('daemon failed to start 5 times in a row', $written);

        $body = $this->decodedBody($written);
        self::assertStringContainsString('The daemon failed to start 5 times in a row', $body);
        self::assertStringContainsString('the last attempt survived 1.50s', $body);
        self::assertStringContainsString('Reason: PHP Fatal error: no database', $body);
    }

    public function testOnlyTheLastLogLineTravelsAndItIsCutShort(): void
    {
        putenv('WATCHDOG_ALERT_TO_ADDRESS=' . self::RECIPIENT);
        $lastLine = str_repeat('x', 260);

        $this->mailer()->sendDaemonFailedStart(
            3,
            0.25,
            "#0 /hilos/framework/backend/Core/Daemon/DockerManager.php(98)\n#1 {main}\n{$lastLine}\n\n",
        );

        $body = $this->decodedBody($this->writtenAlert());
        self::assertStringNotContainsString('DockerManager.php(98)', $body);
        self::assertStringNotContainsString('{main}', $body);
        self::assertStringContainsString('Reason: ' . str_repeat('x', 200) . "\n", $body);
        self::assertStringNotContainsString(str_repeat('x', 201), $body);
    }

    public function testExitingAlertSaysNobodyIsComingBackAndWhy(): void
    {
        putenv('WATCHDOG_ALERT_TO_ADDRESS=' . self::RECIPIENT);

        $this->mailer()->sendWatchdogExiting('RuntimeException: log volume is full in DockerManager.php:98');

        $written = $this->writtenAlert();
        self::assertStringContainsString('watchdog is exiting after its own failure', $written);

        $body = $this->decodedBody($written);
        self::assertStringContainsString('will not restart the daemon again', $body);
        self::assertStringContainsString(
            'Reason: RuntimeException: log volume is full in DockerManager.php:98',
            $body,
        );
    }

    public function testUnconfiguredMailYieldsNoMailerRatherThanAFileFallback(): void
    {
        self::assertNull(WatchdogAlertMailer::fromEnv());
    }

    public function testMailingOnlyTheRecipientIsMissingStillYieldsNoMailer(): void
    {
        putenv('WATCHDOG_ALERT_SMTP_HOST=smtp.example.com');
        putenv('WATCHDOG_ALERT_FROM_ADDRESS=watchdog@example.com');

        self::assertNull(WatchdogAlertMailer::fromEnv());
    }

    public function testAFailingTransportChangesNothingForTheWatchdog(): void
    {
        $transport = new FailedMailTransport();
        $mailer = new WatchdogAlertMailer($transport, self::RECIPIENT, 5000);

        $mailer->sendWatchdogExiting('RuntimeException: anything at all');

        // Nothing escaped, and the transport was left ready rather than latched on its own failure:
        // a second alert has to be sendable, which is what start() refuses on an unconsumed result.
        self::assertFalse($transport->hasResult());
        self::assertFalse($transport->isBusy());
    }

    public function testOneMailerSendsBothAlertsOfARunInTurn(): void
    {
        putenv('WATCHDOG_ALERT_TO_ADDRESS=' . self::RECIPIENT);
        $mailer = $this->mailer();

        $mailer->sendDaemonFailedStart(3, 0.5, 'PHP Fatal error: no database');
        $mailer->sendWatchdogExiting('RuntimeException: log volume is full in DockerManager.php:98');

        $files = glob($this->dir . '/*.eml') ?: [];
        self::assertCount(2, $files);

        $written = array_map(fn(string $file): string => (string)file_get_contents($file), $files);
        self::assertCount(1, array_filter(
            $written,
            static fn(string $eml): bool => str_contains($eml, 'daemon failed to start 3 times in a row'),
        ));
        self::assertCount(1, array_filter(
            $written,
            static fn(string $eml): bool => str_contains($eml, 'watchdog is exiting after its own failure'),
        ));
    }

    /**
     * @return WatchdogAlertMailer Mailer writing to this test's directory, addressed as the env says
     */
    private function mailer(): WatchdogAlertMailer
    {
        return new WatchdogAlertMailer(
            new FileMailTransport($this->dir, 'watchdog@example.com'),
            Hilos::$env->string(EnvConstants::WATCHDOG_ALERT_TO_ADDRESS),
            5000,
        );
    }

    /**
     * @return string The single .eml artifact the alert produced
     */
    private function writtenAlert(): string
    {
        $files = glob($this->dir . '/*.eml') ?: [];
        self::assertCount(1, $files);

        return (string)file_get_contents($files[0]);
    }

    /**
     * @param string $written Encoded .eml artifact
     * @return string The plain-text body, base64-decoded
     */
    private function decodedBody(string $written): string
    {
        $parts = explode("\r\n\r\n", $written, 2);
        self::assertCount(2, $parts);

        return (string)base64_decode(str_replace("\r\n", '', $parts[1]), true);
    }

    private function clearWatchdogEnv(): void
    {
        foreach (self::WATCHDOG_KEYS as $key) {
            putenv($key);
        }
    }
}

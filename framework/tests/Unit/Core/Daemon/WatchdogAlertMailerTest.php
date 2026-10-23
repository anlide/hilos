<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\WatchdogAlertMailer;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\FailedMailTransport;
use Hilos\Mail\FileMailTransport;
use Hilos\Mail\MailTransportInterface;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
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

    private ?HilosDbContext $previousDb = null;

    /** @var array<string, string|false> Time configuration restored after each case */
    private array $previousTimeEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hilos-watchdog-alert-' . getmypid() . '-' . uniqid();
        $this->clearWatchdogEnv();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        Hilos::$db = null;
        foreach ([EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE, EnvConstants::HILOS_PROJECT_TIME_ZONE] as $key) {
            $this->previousTimeEnv[$key->name] = getenv($key->name);
            putenv($key->name);
        }
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        foreach ($this->previousTimeEnv as $key => $value) {
            putenv($key . ($value === false ? '' : '=' . $value));
        }
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

        $before = time();
        $this->mailer()->sendDaemonFailedStart(5, 1.5, "PHP Fatal error: no database\n");

        $written = $this->writtenAlert();
        self::assertStringContainsString('To: ' . self::RECIPIENT, $written);
        self::assertStringContainsString('[Hilos watchdog]', $written);
        self::assertStringContainsString('daemon failed to start 5 times in a row', $written);

        $body = $this->decodedBody($written);
        self::assertStringContainsString('The daemon failed to start 5 times in a row', $body);
        self::assertStringContainsString('the last attempt survived 1.50s', $body);
        self::assertStringContainsString('Reason: PHP Fatal error: no database', $body);
        $this->assertAlertTime($body, $before);
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

        $before = time();
        $this->mailer()->sendWatchdogExiting('RuntimeException: log volume is full in DockerManager.php:98');

        $written = $this->writtenAlert();
        self::assertStringContainsString('watchdog is exiting after its own failure', $written);

        $body = $this->decodedBody($written);
        self::assertStringContainsString('will not restart the daemon again', $body);
        self::assertStringContainsString(
            'Reason: RuntimeException: log volume is full in DockerManager.php:98',
            $body,
        );
        $this->assertAlertTime($body, $before);
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
        $mailer = new WatchdogAlertMailer($transport, self::RECIPIENT, 5000, new DateTimeZone('Europe/Warsaw'));

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

        $before = time();
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=invalid');
        $mailer->sendDaemonFailedStart(3, 0.5, 'PHP Fatal error: no database');
        Hilos::$env = null;
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
        foreach ($written as $eml) {
            $this->assertAlertTime($this->decodedBody($eml), $before);
        }
    }

    /** @return iterable<string, array{string, ?string, string}> Operator policy without an account or DB */
    public static function operatorZones(): iterable
    {
        yield 'recipient uses project' => ['recipient', 'America/New_York', 'America/New_York'];
        yield 'project uses project' => ['project', 'America/New_York', 'America/New_York'];
        yield 'utc ignores project' => ['utc', 'America/New_York', 'UTC'];
        yield 'missing project defaults to UTC' => ['recipient', null, 'UTC'];
    }

    /**
     * @param string $mode Server text policy
     * @param ?string $project Configured project zone or absent env key
     * @param string $expected Zone selected before a mailer is returned
     */
    #[DataProvider('operatorZones')]
    public function testFromEnvSelectsTheOperatorZoneWithoutDatabase(string $mode, ?string $project, string $expected): void
    {
        putenv('WATCHDOG_ALERT_SMTP_HOST=127.0.0.1');
        putenv('WATCHDOG_ALERT_FROM_ADDRESS=watchdog@example.com');
        putenv('WATCHDOG_ALERT_TO_ADDRESS=' . self::RECIPIENT);
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=' . $mode);
        putenv(EnvConstants::HILOS_PROJECT_TIME_ZONE->name . ($project === null ? '' : '=' . $project));

        $mailer = WatchdogAlertMailer::fromEnv();

        self::assertInstanceOf(WatchdogAlertMailer::class, $mailer);
        self::assertSame($expected, new ReflectionProperty(WatchdogAlertMailer::class, 'timeZone')->getValue($mailer)->getName());
        self::assertNull(Hilos::$db);
    }

    public function testInvalidModeIsLoggedAndYieldsNoMailer(): void
    {
        putenv('WATCHDOG_ALERT_SMTP_HOST=127.0.0.1');
        putenv('WATCHDOG_ALERT_FROM_ADDRESS=watchdog@example.com');
        putenv('WATCHDOG_ALERT_TO_ADDRESS=' . self::RECIPIENT);
        putenv(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name . '=invalid');
        mkdir($this->dir);
        $logFile = $this->dir . '/configuration.log';
        Logger::setLogFile($logFile);
        try {
            self::assertNull(WatchdogAlertMailer::fromEnv());
            $written = (string)file_get_contents($logFile);
            self::assertStringContainsString('Watchdog alert mail could not be set up', $written);
            self::assertStringContainsString(EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name, $written);
        } finally {
            Logger::resetLogFile();
        }
    }

    public function testTimeoutStillClosesTheTransportWithoutConsumingAMissingResult(): void
    {
        $transport = $this->createMock(MailTransportInterface::class);
        $transport->expects(self::once())->method('start');
        $transport->method('hasResult')->willReturn(false);
        $transport->expects(self::never())->method('consumeResult');
        $transport->expects(self::once())->method('close');
        $mailer = new WatchdogAlertMailer($transport, self::RECIPIENT, 1, new DateTimeZone('Europe/Warsaw'));

        $mailer->sendWatchdogExiting('timeout');
    }

    /**
     * @return WatchdogAlertMailer Mailer writing to this test's directory, addressed as the env says
     */
    private function mailer(): WatchdogAlertMailer
    {
        return new WatchdogAlertMailer(
            new FileMailTransport($this->dir, 'watchdog@example.com'),
            Hilos::$env[EnvConstants::WATCHDOG_ALERT_TO_ADDRESS]->string(),
            5000,
            new DateTimeZone('Europe/Warsaw'),
        );
    }

    /**
     * @param string $body Decoded alert body
     * @param int $before Earliest possible absolute header moment
     */
    private function assertAlertTime(string $body, int $before): void
    {
        self::assertSame(1, preg_match(
            '/^Time:   (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \+(?:01|02):00) \(Europe\/Warsaw\)$/m',
            $body,
            $matches,
        ));
        $timestamp = new DateTimeImmutable($matches[1])->getTimestamp();
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual(time(), $timestamp);
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

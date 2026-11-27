<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n\Country;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\I18n\Country\IpCountryFile;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The country of an address out of the operator's IP-to-country file (HIL-2310).
 *
 * The file is MaxMind's GeoIP2-Country-Test.mmdb from framework/tests/Fixtures/Mmdb, copied into a
 * temporary ip_country directory. Held down here: no directory and no file answer null without a
 * journal line, a found address answers its code, what is not an address answers null, a damaged
 * file answers null and is reported once - whether it breaks on opening or on a lookup - and the
 * file is read once for the life of the instance.
 */
final class IpCountryFileTest extends TestCase
{
    private ?FsContext $previousFs = null;

    /** Temporary ip_country directory of the case. */
    private string $directory;

    /** Temporary main log file the journal assertions read back. */
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousFs = Hilos::$fs;
        $this->directory = sys_get_temp_dir() . '/hilos-ip-country-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-ip-country-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Hilos::$fs = $this->previousFs;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function testNoCountryIsKnownWithoutTheDirectory(): void
    {
        Hilos::$fs = new IpCountryTestFsContext(null);
        Hilos::$fs->configure();
        $this->putTheTestFile();

        self::assertNull((new IpCountryFile())->countryOf('81.2.69.160'));
        self::assertSame('', file_get_contents($this->logFile));
    }

    public function testNoCountryIsKnownWithoutTheFileAndNothingIsReported(): void
    {
        $this->useTheDirectory();

        self::assertNull((new IpCountryFile())->countryOf('81.2.69.160'));
        self::assertSame('', file_get_contents($this->logFile));
    }

    public function testTheCountryOfAFoundAddressIsItsCode(): void
    {
        $this->useTheDirectory();
        $this->putTheTestFile();
        $countries = new IpCountryFile();

        self::assertSame('GB', $countries->countryOf('81.2.69.160'));
        self::assertSame('JP', $countries->countryOf('2001:218::1'));
        self::assertNull($countries->countryOf('10.0.0.1'));
        self::assertSame('', file_get_contents($this->logFile));
    }

    public function testWhatIsNotAnAddressHasNoCountry(): void
    {
        $this->useTheDirectory();
        $this->putTheTestFile();
        $countries = new IpCountryFile();

        self::assertNull($countries->countryOf(null));
        self::assertNull($countries->countryOf(''));
        self::assertNull($countries->countryOf('not-an-address'));
    }

    public function testADamagedFileIsReportedOnceAndKnowsNoCountry(): void
    {
        $this->useTheDirectory();
        file_put_contents($this->directory . '/' . IpCountryFile::FILE_NAME, 'not a MaxMind DB file');
        $countries = new IpCountryFile();

        self::assertNull($countries->countryOf('81.2.69.160'));
        self::assertNull($countries->countryOf('2001:218::1'));

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        self::assertStringContainsString(
            'IP-to-country file ' . $this->directory . '/' . IpCountryFile::FILE_NAME . ' cannot be read: ',
            $lines[0],
        );
        self::assertStringEndsWith('; every country is unknown until the daemon restarts', $lines[0]);
    }

    public function testAFileDamagedOnLookupIsReportedOnceAndKnowsNoCountry(): void
    {
        $this->useTheDirectory();
        $bytes = file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Mmdb/GeoIP2-Country-Test.mmdb');
        // Metadata intact, search tree zeroed: the file opens, and every lookup walks node 0 deeper than the address.
        $marker = strrpos($bytes, "\xAB\xCD\xEFMaxMind.com");
        file_put_contents($this->directory . '/' . IpCountryFile::FILE_NAME, str_repeat("\0", $marker) . substr($bytes, $marker));
        $countries = new IpCountryFile();

        self::assertNull($countries->countryOf('81.2.69.160'));
        self::assertNull($countries->countryOf('2001:218::1'));

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        self::assertStringContainsString(
            'IP-to-country file ' . $this->directory . '/' . IpCountryFile::FILE_NAME . ' cannot be read: MaxMind DB search tree',
            $lines[0],
        );
        self::assertStringEndsWith('; every country is unknown until the daemon restarts', $lines[0]);
    }

    public function testAFileReplacedAfterTheFirstLookupIsNotReadAgain(): void
    {
        $this->useTheDirectory();
        $this->putTheTestFile();
        $countries = new IpCountryFile();
        self::assertSame('GB', $countries->countryOf('81.2.69.160'));

        file_put_contents($this->directory . '/' . IpCountryFile::FILE_NAME, 'not a MaxMind DB file');

        self::assertSame('GB', $countries->countryOf('81.2.69.160'));
        self::assertSame('', file_get_contents($this->logFile));
    }

    private function useTheDirectory(): void
    {
        Hilos::$fs = new IpCountryTestFsContext($this->directory);
        Hilos::$fs->configure();
    }

    private function putTheTestFile(): void
    {
        copy(
            dirname(__DIR__, 3) . '/Fixtures/Mmdb/GeoIP2-Country-Test.mmdb',
            $this->directory . '/' . IpCountryFile::FILE_NAME,
        );
    }
}

/** A context registering the ip_country directory of the case, or nothing. */
final class IpCountryTestFsContext extends FsContext
{
    /**
     * @param string|null $directory The ip_country directory, or null to register none
     */
    public function __construct(private readonly ?string $directory)
    {
    }

    /** Registers the ip_country directory of the case as the cluster's, when there is one. */
    public function configure(): void
    {
        if ($this->directory !== null) {
            $this->registerDirectory(FsContext::IP_COUNTRY, $this->directory, DirectoryScope::CLUSTER);
        }
    }
}

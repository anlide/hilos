<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs\Mmdb;

use Hilos\Fs\Exception\MmdbFormatException;
use Hilos\Fs\FsPath;
use Hilos\Fs\Mmdb\MmdbReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The MaxMind DB reader over MaxMind's own test databases (HIL-2310).
 *
 * The files under framework/tests/Fixtures/Mmdb are unchanged copies of test-data/ of
 * maxmind/MaxMind-DB at commit 5c972a8a85df5557c122f79d6e172c7a5cfe93c5. The expectations of
 * GeoIP2-Country-Test come from source-data/GeoIP2-Country-Test.json of that commit; the
 * MaxMind-DB-test-* files have no source data and are written by pkg/writer/maxmind.go there:
 * one record {"ip": <network address>} per network of 1.1.1.1–1.1.1.32 or ::1:ffff:ffff–::2:0:59.
 *
 * Held down here: a country is found by IPv6 and by IPv4 in an IPv6 tree, an address outside every
 * network is not found, a 16-byte address is not in an IPv4 tree, all three record sizes walk the
 * tree, and bytes that are not a whole MaxMind DB file are refused. Files no fixture has are built
 * here byte by byte ({@see self::mmdb()}): a record whose pointers fan out into too many values or
 * too many bytes, and a search tree that points past the data section, are refused on lookup.
 */
final class MmdbReaderTest extends TestCase
{
    public function testFindsTheCountryOfAnIpv6Address(): void
    {
        $record = $this->reader('GeoIP2-Country-Test.mmdb')->find(inet_pton('2001:218::1'));

        $this->assertSame('JP', $record['country']['iso_code']);
    }

    public function testFindsTheCountryOfAnIpv4AddressInAnIpv6Tree(): void
    {
        $reader = $this->reader('GeoIP2-Country-Test.mmdb');

        $this->assertSame('GB', $reader->find(inet_pton('81.2.69.160'))['country']['iso_code']);
        $sweden = $reader->find(inet_pton('89.160.20.112'))['country'];
        $this->assertSame('SE', $sweden['iso_code']);
        $this->assertTrue($sweden['is_in_european_union']);
        $this->assertSame(2661886, $sweden['geoname_id']);
        $this->assertSame('Schweden', $sweden['names']['de']);
    }

    public function testAnAddressOutsideEveryNetworkIsNotFound(): void
    {
        $reader = $this->reader('GeoIP2-Country-Test.mmdb');

        $this->assertNull($reader->find(inet_pton('10.0.0.1')));
        $this->assertNull($reader->find(inet_pton('2001:db8::1')));
    }

    public function testAnIpv6AddressIsNotInAnIpv4Tree(): void
    {
        $reader = $this->reader('MaxMind-DB-test-ipv4-24.mmdb');

        $this->assertNull($reader->find(inet_pton('::1:ffff:ffff')));
        $this->assertNull($reader->find(inet_pton('::ffff:1.1.1.1')));
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function lookups(): array
    {
        return [
            'ipv4-24 found' => ['MaxMind-DB-test-ipv4-24.mmdb', '1.1.1.3', '1.1.1.2'],
            'ipv4-24 edge' => ['MaxMind-DB-test-ipv4-24.mmdb', '1.1.1.32', '1.1.1.32'],
            'ipv4-24 not found' => ['MaxMind-DB-test-ipv4-24.mmdb', '1.1.1.33', null],
            'ipv6-24 found' => ['MaxMind-DB-test-ipv6-24.mmdb', '::2:0:45', '::2:0:40'],
            'ipv6-24 not found' => ['MaxMind-DB-test-ipv6-24.mmdb', '::2:0:5a', null],
            'ipv6-28 found' => ['MaxMind-DB-test-ipv6-28.mmdb', '::1:ffff:ffff', '::1:ffff:ffff'],
            'ipv6-28 not found' => ['MaxMind-DB-test-ipv6-28.mmdb', '::1:ffff:fffe', null],
            'ipv6-32 found' => ['MaxMind-DB-test-ipv6-32.mmdb', '::2:0:59', '::2:0:58'],
            'ipv6-32 not found' => ['MaxMind-DB-test-ipv6-32.mmdb', '::3:0:0', null],
        ];
    }

    #[DataProvider('lookups')]
    public function testWalksTheTreeOfEveryRecordSize(string $file, string $address, ?string $network): void
    {
        $record = $this->reader($file)->find(inet_pton($address));

        $this->assertSame($network === null ? null : ['ip' => $network], $record);
    }

    public function testATruncatedFileIsRefused(): void
    {
        $bytes = FsPath::read(self::fixture('GeoIP2-Country-Test.mmdb'));

        $this->expectException(MmdbFormatException::class);
        new MmdbReader(substr($bytes, 0, -10));
    }

    public function testBytesWithoutTheMetadataMarkerAreRefused(): void
    {
        $bytes = FsPath::read(self::fixture('MaxMind-DB-test-ipv4-24.mmdb'));

        $this->expectException(MmdbFormatException::class);
        new MmdbReader(str_replace("\xAB\xCD\xEFMaxMind.com", "\xAB\xCD\xEFMinDMax.com", $bytes));
    }

    public function testARecordWhosePointersFanOutIntoTooManyValuesIsRefused(): void
    {
        // Offset 0: the uint16 1; then 40 levels, each an array of two pointers to the level below: 2^40 values.
        $data = self::uint16(1);
        $level = 0;
        for ($depth = 1; $depth <= 40; $depth++) {
            $offset = strlen($data);
            $data .= self::arrayOf(2) . self::pointer($level) . self::pointer($level);
            $level = $offset;
        }
        $reader = new MmdbReader(self::mmdb($data, $level));

        $this->expectException(MmdbFormatException::class);
        $this->expectExceptionMessage('values');
        $reader->find(inet_pton('1.2.3.4'));
    }

    public function testARecordWhosePointersFanOutIntoTooManyBytesIsRefused(): void
    {
        // Offset 0: a string of 2000 bytes (size field 30, then 2000 - 285 in two bytes); then an array
        // (size field 29, then 100 - 29) of 100 pointers to it: 200 000 bytes out of a file of about 2 300.
        $data = "\x5E" . pack('n', 2000 - 285) . str_repeat('x', 2000);
        $array = strlen($data);
        $data .= "\x1D\x04" . chr(100 - 29) . str_repeat(self::pointer(0), 100);
        $reader = new MmdbReader(self::mmdb($data, $array));

        $this->expectException(MmdbFormatException::class);
        $this->expectExceptionMessage('bytes');
        $reader->find(inet_pton('1.2.3.4'));
    }

    public function testASearchTreePointingPastTheDataSectionIsRefused(): void
    {
        $data = "\xE1" . self::string('country') . self::string('GB');
        // The record points at the metadata map right after the marker: a map, yet not a record.
        $reader = new MmdbReader(self::mmdb($data, strlen($data) + strlen("\xAB\xCD\xEFMaxMind.com")));

        $this->expectException(MmdbFormatException::class);
        $reader->find(inet_pton('1.2.3.4'));
    }

    /**
     * Bytes of an IPv4 MaxMind DB of one node with 24-bit records, both pointing at one offset of the data section.
     *
     * @param string $data Data section
     * @param int $recordOffset Offset of the record inside the data section
     * @return string Whole file
     */
    private static function mmdb(string $data, int $recordOffset): string
    {
        // A record value is the node count (1), plus the 16-byte separator, plus the offset in the data section.
        $record = substr(pack('N', 1 + 16 + $recordOffset), 1);
        $metadata = "\xE4"
            . self::string('binary_format_major_version') . self::uint16(2)
            . self::string('node_count') . "\xC4" . pack('N', 1)
            . self::string('record_size') . self::uint16(24)
            . self::string('ip_version') . self::uint16(4);

        return $record . $record . str_repeat("\0", 16) . $data . "\xAB\xCD\xEFMaxMind.com" . $metadata;
    }

    /**
     * @param string $value String shorter than 29 bytes
     * @return string Encoded UTF-8 string
     */
    private static function string(string $value): string
    {
        return chr(0x40 | strlen($value)) . $value;
    }

    /**
     * @param int $value Value below 65536
     * @return string Encoded uint16 of two bytes
     */
    private static function uint16(int $value): string
    {
        return "\xA2" . pack('n', $value);
    }

    /**
     * @param int $entries Entries, fewer than 29
     * @return string Control and extended type bytes of an array
     */
    private static function arrayOf(int $entries): string
    {
        return chr($entries) . "\x04";
    }

    /**
     * @param int $offset Offset in the data section, below 2048
     * @return string Encoded two-byte pointer
     */
    private static function pointer(int $offset): string
    {
        return chr(0x20 | $offset >> 8) . chr($offset & 0xff);
    }

    private function reader(string $file): MmdbReader
    {
        return new MmdbReader(FsPath::read(self::fixture($file)));
    }

    private static function fixture(string $file): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Mmdb/' . $file;
    }
}

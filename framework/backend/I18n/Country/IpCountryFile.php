<?php

declare(strict_types=1);

namespace Hilos\I18n\Country;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\MmdbFormatException;
use Hilos\Fs\Mmdb\MmdbReader;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use InvalidArgumentException;

/**
 * The country of a connection's address, out of the IP-to-country file (HIL-2310).
 *
 * The file is the operator's: a MaxMind DB country file named {@see self::FILE_NAME} in the
 * cluster's directory {@see FsContext::IP_COUNTRY}. It is read whole on the first call, about
 * 10 MB in every process that asks, and a file put in later is read after the daemon restarts.
 * The master never asks (hilos-master-process): whoever looks countries up holds one instance per
 * process. Not shared through Hilos::$rt (framework-first): the bytes are the content of a file
 * nobody changes while the process lives, a resource of the process, not data other processes
 * write or wait for.
 */
final class IpCountryFile
{
    /** Name of the MaxMind DB country file inside the IP-to-country directory. */
    public const string FILE_NAME = 'country.mmdb';

    /** A country code as the file is trusted to carry it: ISO 3166-1 alpha-2, upper case. */
    private const string COUNTRY_CODE_PATTERN = '/^[A-Z]{2}$/';

    /** Key of the country map inside a record of the file. */
    private const string RECORD_COUNTRY = 'country';

    /** Key of the country code inside the country map. */
    private const string RECORD_ISO_CODE = 'iso_code';

    /** Whether the first call has looked for the file; it is looked for once per instance. */
    private bool $opened = false;

    /** The reader of the file, or null while no country can be known. */
    private ?MmdbReader $reader = null;

    /** Path of the file, set when the directory is found, for the journal line. */
    private string $path;

    /**
     * Never throws: a missing directory or file and an address that is not one answer null, and
     * a damaged file writes one journal line and answers null until the daemon restarts.
     *
     * @param string|null $ip Address in presentation form, IPv4 or IPv6
     * @return string|null ISO 3166-1 alpha-2 code in upper case, or null when the country is not known
     */
    public function countryOf(?string $ip): ?string
    {
        if (!$this->opened) {
            $this->opened = true;
            $this->reader = $this->open();
        }
        if ($this->reader === null || $ip === null || $ip === '') {
            return null;
        }
        $packedAddress = inet_pton($ip);
        if ($packedAddress === false) {
            return null;
        }

        try {
            $record = $this->reader->find($packedAddress);
        } catch (MmdbFormatException $damaged) {
            $this->reader = null;
            $this->reportUnreadable($damaged->getMessage());

            return null;
        } catch (InvalidArgumentException) {
            // inet_pton() packs 4 or 16 bytes, the only lengths find() takes: an address it refuses has no country.
            return null;
        }
        $code = $record[self::RECORD_COUNTRY][self::RECORD_ISO_CODE] ?? null;

        return is_string($code) && preg_match(self::COUNTRY_CODE_PATTERN, $code) === 1 ? $code : null;
    }

    /**
     * @return MmdbReader|null Reader of the file, or null when there is no directory, no file, or the file cannot be read
     */
    private function open(): ?MmdbReader
    {
        if (Hilos::$fs?->hasDirectory(FsContext::IP_COUNTRY) !== true) {
            return null;
        }
        $file = Hilos::$fs->ip_country[self::FILE_NAME];
        $this->path = $file->getPath();

        try {
            return new MmdbReader($file->read());
        } catch (FileNotFoundException) {
            return null;
        } catch (FileReadException | MmdbFormatException $unreadable) {
            $this->reportUnreadable($unreadable->getMessage());

            return null;
        }
    }

    /**
     * @param string $reason Why the file cannot be read
     */
    private function reportUnreadable(string $reason): void
    {
        Logger::warning(
            "IP-to-country file {$this->path} cannot be read: {$reason}; every country is unknown until the daemon restarts",
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Logger;

/**
 * TrustedProxies - the networks whose forwarded client address the framework believes (HIL-680).
 *
 * Read on every handshake ({@see fromEnv()}) and asked one question ({@see trusts()}):
 * does this TCP peer speak for someone else? Only a peer inside one of the configured
 * networks does, and only then is the X-Real-IP header of that handshake read at all.
 *
 * The empty list is the default and answers no to everyone, which is the behavior of a
 * deployment that faces the network directly: a header nobody is trusted to send cannot
 * be used to choose the address the throttle counts by.
 *
 * Nothing is cached between handshakes. Splitting one to three entries and packing them
 * costs microseconds on the accept loop, while a cache would need invalidating whenever
 * the environment is re-read and would carry one test case's list into the next. The only
 * statics here are the flags that keep a misconfiguration from logging once per connection.
 */
final class TrustedProxies
{
    /** Separates the configured networks in the env value. */
    private const string SEPARATOR = ',';

    /** Separates an address from its prefix length inside one configured network. */
    private const string PREFIX_SEPARATOR = '/';

    /** Key under which a parsed network holds its packed address. */
    private const string NETWORK_ADDRESS = 'address';

    /** Key under which a parsed network holds its prefix length in bits. */
    private const string NETWORK_BITS = 'bits';

    /** Bits in one byte of a packed address, the unit the prefix is split into. */
    private const int BITS_PER_BYTE = 8;

    /** All bits of one byte, the starting point of the partial-byte mask. */
    private const int BYTE_MASK = 0xFF;

    /** Byte length inet_pton() packs an IPv4 address into. */
    private const int IPV4_BYTES = 4;

    /** Byte length inet_pton() packs an IPv6 address into. */
    private const int IPV6_BYTES = 16;

    /** Leading bytes an IPv4-mapped IPv6 address carries in front of its four IPv4 ones. */
    private const string IPV4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /** @var bool Whether an unusable entry has already been reported in this process */
    private static bool $unusableEntryReported = false;

    /** @var bool Whether an entry trusting every peer has already been reported in this process */
    private static bool $everyoneEntryReported = false;

    /**
     * @param list<array{address: string, bits: int}> $networks Parsed networks, packed address and prefix length
     */
    private function __construct(private readonly array $networks)
    {
    }

    /**
     * Reads the trusted networks from the environment, dropping every entry it refuses.
     *
     * An entry is refused when it cannot be parsed, and when its prefix is zero bits long:
     * that one parses perfectly and trusts every peer there is, which is the hole this
     * class exists to close.
     *
     * A dropped entry narrows trust rather than widening it, so the deployment keeps
     * working and the mistake is invisible in behavior - which is why the drop is logged.
     *
     * @return self List built from the environment, empty when nothing is configured
     * @throws EnvException When the configured value cannot be read from the environment
     */
    public static function fromEnv(): self
    {
        return new self(self::parseNetworks(Hilos::$env?->string(EnvConstants::HILOS_TRUSTED_PROXIES)));
    }

    /**
     * Whether an address belongs to one of the trusted networks.
     *
     * An IPv4-mapped address is compared as the IPv4 address it carries: a peer accepted
     * on a dual-stack socket arrives in that form, and a plainly written IPv4 network has
     * to recognize it.
     *
     * @param string $ip Address to test, as read from the socket
     * @return bool True when the address falls inside a configured network
     */
    public function trusts(string $ip): bool
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }

        foreach ($this->networks as $network) {
            if (self::contains($network[self::NETWORK_ADDRESS], $network[self::NETWORK_BITS], $packed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ?string $configured Comma-separated networks as configured, or null with no environment
     * @return list<array{address: string, bits: int}> Networks that were accepted, in configured order
     */
    private static function parseNetworks(?string $configured): array
    {
        if ($configured === null) {
            return [];
        }

        $networks = [];
        foreach (explode(self::SEPARATOR, $configured) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $network = self::parseNetwork($entry);
            if ($network === null) {
                self::reportUnusableEntry($entry);
                continue;
            }

            if ($network[self::NETWORK_BITS] === 0) {
                self::reportEveryoneEntry($entry);
                continue;
            }

            $networks[] = $network;
        }

        return $networks;
    }

    /**
     * Parses one configured entry into a packed address and a prefix length.
     *
     * A single address has to be written as /32 or /128: the alternative is guessing a
     * prefix for a value that a typo can turn into a whole network.
     *
     * @param string $entry One trimmed entry of the configured list
     * @return ?array{address: string, bits: int} Parsed network, or null when the entry is unusable
     */
    private static function parseNetwork(string $entry): ?array
    {
        $parts = explode(self::PREFIX_SEPARATOR, $entry);
        if (count($parts) !== 2 || preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
            return null;
        }

        $address = self::pack($parts[0]);
        if ($address === null) {
            return null;
        }

        $bits = (int)$parts[1];
        if ($bits > strlen($address) * self::BITS_PER_BYTE) {
            return null;
        }

        return [self::NETWORK_ADDRESS => $address, self::NETWORK_BITS => $bits];
    }

    /**
     * @param string $ip Address in presentation form
     * @return ?string Packed address, IPv4-mapped form reduced to its four IPv4 bytes, or null when it is not an address
     */
    private static function pack(string $ip): ?string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === self::IPV6_BYTES && str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return substr($packed, strlen(self::IPV4_MAPPED_PREFIX), self::IPV4_BYTES);
        }

        return $packed;
    }

    /**
     * Compares a packed address against a packed network byte by byte, masking the last one.
     *
     * @param string $network Packed network address
     * @param int $bits Prefix length of the network
     * @param string $address Packed address to test
     * @return bool True when the address falls inside the network
     */
    private static function contains(string $network, int $bits, string $address): bool
    {
        if (strlen($network) !== strlen($address)) {
            return false;
        }

        $wholeBytes = intdiv($bits, self::BITS_PER_BYTE);
        if (strncmp($network, $address, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % self::BITS_PER_BYTE;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = self::BYTE_MASK << (self::BITS_PER_BYTE - $remainingBits) & self::BYTE_MASK;

        return (ord($network[$wholeBytes]) & $mask) === (ord($address[$wholeBytes]) & $mask);
    }

    /**
     * Reports an unusable entry once per process.
     *
     * Repeating it would mean a line per connection for a mistake that is made once, in a
     * file nobody reads while the log fills; the first line says everything the next
     * thousand would.
     *
     * @param string $entry Entry that could not be parsed
     */
    private static function reportUnusableEntry(string $entry): void
    {
        if (self::$unusableEntryReported) {
            return;
        }

        self::$unusableEntryReported = true;
        Logger::error(
            'Ignoring an unparsable entry in ' . EnvConstants::HILOS_TRUSTED_PROXIES->name,
            ['entry' => $entry],
        );
    }

    /**
     * Reports an entry whose prefix trusts every peer, once per process.
     *
     * It carries its own complaint rather than the unparsable one because nothing is
     * wrong with how it is written: an operator sent looking for a typo would not find
     * one. It also carries its own flag, so that a list holding both mistakes still
     * says the one this refusal exists for.
     *
     * @param string $entry Entry that trusts every peer
     */
    private static function reportEveryoneEntry(string $entry): void
    {
        if (self::$everyoneEntryReported) {
            return;
        }

        self::$everyoneEntryReported = true;
        Logger::error(
            'Refusing an entry that trusts every peer in ' . EnvConstants::HILOS_TRUSTED_PROXIES->name
                . '; name the network your proxy connects from',
            ['entry' => $entry],
        );
    }
}

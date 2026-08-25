<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the list of networks whose forwarded address is believed (HIL-680).
 *
 * The list decides one thing - whether a TCP peer is allowed to name someone else - and
 * every case here is a way that decision can be got wrong in a deployment: an empty list
 * that must trust nobody, a single address that must not quietly widen into a network,
 * an IPv4-mapped peer off a dual-stack socket that must still match a plainly written
 * IPv4 network, and a typo that must cost only its own entry.
 */
final class TrustedProxiesTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::HILOS_TRUSTED_PROXIES->name);
    }

    public function testAnEmptyListTrustsNobody(): void
    {
        $proxies = $this->configured('');

        $this->assertFalse($proxies->trusts('10.1.2.3'));
        $this->assertFalse($proxies->trusts('127.0.0.1'));
        $this->assertFalse($proxies->trusts('::1'));
    }

    public function testASingleAddressTrustsOnlyItself(): void
    {
        $proxies = $this->configured('10.1.2.3/32');

        $this->assertTrue($proxies->trusts('10.1.2.3'));
        $this->assertFalse($proxies->trusts('10.1.2.4'));
    }

    public function testASingleIpv6AddressTrustsOnlyItself(): void
    {
        $proxies = $this->configured('2001:db8::1/128');

        $this->assertTrue($proxies->trusts('2001:db8::1'));
        $this->assertFalse($proxies->trusts('2001:db8::2'));
    }

    public function testAnIpv4NetworkTrustsTheAddressesInsideIt(): void
    {
        $proxies = $this->configured('10.0.0.0/8');

        $this->assertTrue($proxies->trusts('10.1.2.3'));
        $this->assertTrue($proxies->trusts('10.255.255.255'));
        $this->assertFalse($proxies->trusts('11.0.0.1'));
        $this->assertFalse($proxies->trusts('192.168.1.1'));
    }

    public function testANetworkOnAPartialByteSplitsThatByte(): void
    {
        $proxies = $this->configured('172.16.0.0/12');

        $this->assertTrue($proxies->trusts('172.16.0.1'));
        $this->assertTrue($proxies->trusts('172.31.255.254'));
        $this->assertFalse($proxies->trusts('172.32.0.1'));
        $this->assertFalse($proxies->trusts('172.15.255.254'));
    }

    public function testAnIpv6NetworkTrustsTheAddressesInsideIt(): void
    {
        $proxies = $this->configured('2001:db8::/32');

        $this->assertTrue($proxies->trusts('2001:db8::1'));
        $this->assertTrue($proxies->trusts('2001:db8:ffff::1'));
        $this->assertFalse($proxies->trusts('2001:db9::1'));
    }

    public function testAnIpv4MappedAddressIsMatchedByAnIpv4Network(): void
    {
        $proxies = $this->configured('10.0.0.0/8');

        $this->assertTrue($proxies->trusts('::ffff:10.1.2.3'));
        $this->assertFalse($proxies->trusts('::ffff:11.1.2.3'));
    }

    public function testAnIpv4NetworkDoesNotTrustARealIpv6Address(): void
    {
        $proxies = $this->configured('10.0.0.0/8');

        $this->assertFalse($proxies->trusts('2001:db8::1'));
    }

    public function testSpacesAroundEntriesAreIgnored(): void
    {
        $proxies = $this->configured(' 10.0.0.0/8 ,  192.168.0.0/16 ');

        $this->assertTrue($proxies->trusts('10.1.2.3'));
        $this->assertTrue($proxies->trusts('192.168.1.1'));
    }

    /**
     * @param string $configured Entry that cannot be parsed as a network
     */
    #[DataProvider('unusableEntries')]
    public function testAnUnusableEntryIsDroppedAndItsNeighborKeepsWorking(string $configured): void
    {
        $proxies = $this->configured($configured . ',10.0.0.0/8');

        $this->assertTrue($proxies->trusts('10.1.2.3'));
        $this->assertFalse($proxies->trusts('192.168.1.1'));
    }

    /**
     * @return array<string, array{string}> Entry that must be dropped, by what is wrong with it
     */
    public static function unusableEntries(): array
    {
        return [
            'no prefix at all' => ['192.168.1.1'],
            'prefix past the address size' => ['192.168.0.0/33'],
            'prefix of an IPv6 size on an IPv4 address' => ['192.168.0.0/64'],
            'not an address' => ['not-a-network/24'],
            'a host name' => ['proxy.internal/32'],
            'an empty prefix' => ['192.168.0.0/'],
            'two prefixes' => ['192.168.0.0/16/16'],
        ];
    }

    public function testTheWholeListCanBeUnusable(): void
    {
        $proxies = $this->configured('nonsense,10.0.0.0');

        $this->assertFalse($proxies->trusts('10.1.2.3'));
    }

    /**
     * Builds the list a configured env value produces.
     *
     * @param string $value Comma-separated networks as a deployment would configure them
     * @return TrustedProxies List read from that value
     */
    private function configured(string $value): TrustedProxies
    {
        putenv(EnvConstants::HILOS_TRUSTED_PROXIES->name . '=' . $value);

        return TrustedProxies::fromEnv();
    }
}

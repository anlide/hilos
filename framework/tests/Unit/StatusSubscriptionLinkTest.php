<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Status\StatusSubscriptionLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Public mail addresses are validated before a caller can issue a challenge. */
final class StatusSubscriptionLinkTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousUrl;

    /** Isolates the URL from the process environment and any previously loaded accessor. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousUrl = getenv(EnvConstants::HILOS_STATUS_URL->name);
        putenv(EnvConstants::HILOS_STATUS_URL->name);
        Hilos::$env = new EnvAccessor();
    }

    /** Restores the process environment even after a validation refusal. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv($this->previousUrl === false ? EnvConstants::HILOS_STATUS_URL->name
            : EnvConstants::HILOS_STATUS_URL->name . '=' . $this->previousUrl);
    }

    public function testEnvironmentDefaultIsEmptyAndDoesNotRequireStartupConfiguration(): void
    {
        $entry = EnvCatalogStub::getCatalog()[EnvConstants::HILOS_STATUS_URL->name];
        self::assertSame(EnvCatalogConstants::TYPE_STRING, $entry[EnvCatalogConstants::CATALOG_ENTRY_TYPE]);
        self::assertSame('', $entry[EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
        self::assertFalse($entry[EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING]);
        self::assertSame('', Hilos::$env[EnvConstants::HILOS_STATUS_URL]->string());
        $this->expectException(ValidationException::class);
        StatusSubscriptionLink::fromEnvironment();
    }

    public function testMissingEnvironmentRefusesSafely(): void
    {
        Hilos::$env = null;
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Status subscriptions are unavailable. Try again later.');
        StatusSubscriptionLink::fromEnvironment();
    }

    public function testConfiguredAddressIsCapturedAndSecretsStayInFragments(): void
    {
        putenv('HILOS_STATUS_URL=https://service.example:8443/status');
        $links = StatusSubscriptionLink::fromEnvironment();
        putenv('HILOS_STATUS_URL=');
        $confirmation = $links->confirmUrl(str_repeat('a', 64));
        $unsubscribe = $links->unsubscribeUrl(str_repeat('B', 64));
        self::assertSame('https://service.example:8443/status', $links->statusUrl);
        self::assertSame('https://service.example:8443/status#status-confirm=' . str_repeat('a', 64), $confirmation);
        self::assertSame('https://service.example:8443/status#status-unsubscribe=' . str_repeat('B', 64), $unsubscribe);
        foreach ([$confirmation, $unsubscribe] as $url) {
            self::assertSame('/status', parse_url($url, PHP_URL_PATH));
            self::assertNull(parse_url($url, PHP_URL_QUERY));
        }
        self::assertSame('http://localhost/status', new StatusSubscriptionLink('http://localhost/status')->statusUrl);
        self::assertSame('https://[::1]/status', new StatusSubscriptionLink('https://[::1]/status')->statusUrl);
    }

    /** @return iterable<string, array{string}> Unsafe or non-public-page addresses */
    public static function invalidAddresses(): iterable
    {
        foreach ([
            'empty' => '', 'relative' => '/status', 'scheme-relative' => '//service.example/status',
            'unsupported scheme' => 'ftp://service.example/status', 'wrong path' => 'https://service.example/other',
            'no path' => 'https://service.example', 'trailing slash' => 'https://service.example/status/',
            'credentials' => 'https://user:password@service.example/status', 'empty user' => 'https://@service.example/status',
            'query' => 'https://service.example/status?token=secret', 'empty query' => 'https://service.example/status?',
            'fragment' => 'https://service.example/status#token', 'empty fragment' => 'https://service.example/status#',
            'padded' => ' https://service.example/status', 'control' => "https://service.example/status\n",
            'invalid port' => 'https://service.example:65536/status',
        ] as $label => $url) {
            yield $label => [$url];
        }
    }

    /** @param string $url Invalid configured public address */
    #[DataProvider('invalidAddresses')]
    public function testInvalidAddressesRefuseWithoutEchoingTheirContent(string $url): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Status subscriptions are unavailable. Try again later.');
        new StatusSubscriptionLink($url);
    }

    /** @return iterable<string, array{string}> Values that must never be concatenated into a link */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 63)];
        yield 'long' => [str_repeat('a', 65)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'newline' => [str_repeat('a', 64) . "\n"];
        yield 'query injection' => [str_repeat('a', 64) . '&email=person@example.org'];
    }

    /** @param string $token Malformed secret */
    #[DataProvider('invalidTokens')]
    public function testBothLinkOperationsRejectMalformedSecrets(string $token): void
    {
        $links = new StatusSubscriptionLink('https://service.example/status');
        foreach (['confirmUrl', 'unsubscribeUrl'] as $operation) {
            try {
                $links->$operation($token);
                self::fail('A malformed secret must be refused');
            } catch (ValidationException $failure) {
                self::assertSame('This subscription link is invalid. Request a new link.', $failure->getMessage());
            }
        }
    }
}

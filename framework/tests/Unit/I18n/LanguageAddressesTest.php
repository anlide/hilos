<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\LanguageAddresses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The declaration of language addresses as the daemon preflight and the handshake read it. */
final class LanguageAddressesTest extends TestCase
{
    private const string DECLARED = 'de=de.example.org,ru=ru.example.org';

    private ?EnvAccessor $previousEnv;
    private string|false $previousHosts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousHosts = getenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        if ($this->previousHosts === false) {
            putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name);
        } else {
            putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=' . $this->previousHosts);
        }
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testUnsetAndEmptyDeclareNoAddresses(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name);
        $this->assertSame([], LanguageAddresses::hosts());

        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=');
        $this->assertSame([], LanguageAddresses::hosts());

        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=  ');
        $this->assertSame([], LanguageAddresses::hosts());
    }

    public function testPairsAreTrimmedAndATrailingSeparatorIsSkipped(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=de=de.example.org, ru = ru.example.org ,');

        $this->assertSame(['de' => 'de.example.org', 'ru' => 'ru.example.org'], LanguageAddresses::hosts());
    }

    public function testHostIsReadInLowerCase(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=de=DE.Example.org');

        $this->assertSame(['de' => 'de.example.org'], LanguageAddresses::hosts());
    }

    public function testDeclaredValueIsReadAnewOnEveryCall(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=de=de.example.org');
        $this->assertSame('de', LanguageAddresses::languageOfOrigin('https://de.example.org'));

        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=ru=de.example.org');
        $this->assertSame('ru', LanguageAddresses::languageOfOrigin('https://de.example.org'));
    }

    /** @return iterable<string, array{string, string}> Malformed declaration and the element its refusal names */
    public static function malformedDeclarations(): iterable
    {
        yield 'no separator' => ['de', 'de'];
        yield 'no host' => ['de=', 'de='];
        yield 'no code' => ['=de.example.org', '=de.example.org'];
        yield 'two separators' => ['de=a=b', 'de=a=b'];
        yield 'uppercase code' => ['DE=de.example.org', 'DE=de.example.org'];
        yield 'unknown code' => ['ez=ez.example.org', 'ez=ez.example.org'];
        yield 'scheme' => ['de=https://de.example.org', 'de=https://de.example.org'];
        yield 'port' => ['de=de.example.org:8080', 'de=de.example.org:8080'];
        yield 'path' => ['de=de.example.org/de', 'de=de.example.org/de'];
        yield 'too long' => ['de=' . str_repeat('a.', 126) . 'aa', 'de=' . str_repeat('a.', 126) . 'aa'];
        yield 'second host for one language' => ['de=a.example.org,de=b.example.org', 'de=b.example.org'];
        yield 'second language for one host' => ['de=x.example.org,ru=X.example.org', 'ru=X.example.org'];
    }

    #[DataProvider('malformedDeclarations')]
    public function testMalformedDeclarationNamesTheVariableAndTheElement(string $declaration, string $element): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=' . $declaration);

        $this->assertRefused(static fn (): array => LanguageAddresses::hosts(), $element);
    }

    public function testLongestHostIsAccepted(): void
    {
        $host = str_repeat('a.', 125) . 'aaa';
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=de=' . $host);

        $this->assertSame(['de' => $host], LanguageAddresses::hosts());
    }

    /** @return iterable<string, array{?string, ?string}> Origin header and the language of its address */
    public static function origins(): iterable
    {
        yield 'declared host' => ['https://de.example.org', 'de'];
        yield 'case and port' => ['http://DE.Example.org:5173', 'de'];
        yield 'final dot' => ['https://de.example.org.', 'de'];
        yield 'another declared host' => ['https://ru.example.org', 'ru'];
        yield 'main address' => ['https://example.org', null];
        yield 'no header' => [null, null];
        yield 'empty header' => ['', null];
        yield 'opaque origin' => ['null', null];
        yield 'not a url' => ['not a url', null];
    }

    #[DataProvider('origins')]
    public function testOriginNamesTheLanguageOfItsHost(?string $origin, ?string $expected): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=' . self::DECLARED);

        $this->assertSame($expected, LanguageAddresses::languageOfOrigin($origin));
    }

    public function testWithoutDeclarationNoOriginNamesALanguage(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=');

        $this->assertNull(LanguageAddresses::languageOfOrigin('https://de.example.org'));
    }

    public function testAFacadeWithI18nIsRefusedAMalformedDeclaration(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=de=de.example.org,ez=ez.example.org');

        $this->assertRefused(static function (): void {
            LanguageAddresses::assertConfiguredFor(LanguageAddressesI18nHilos::class);
        }, 'ez=ez.example.org');
    }

    public function testAFacadeWithI18nAcceptsAWellFormedDeclaration(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=' . self::DECLARED);

        $this->expectNotToPerformAssertions();
        LanguageAddresses::assertConfiguredFor(LanguageAddressesI18nHilos::class);
    }

    public function testAFacadeWithoutI18nIsNotAskedForTheVariable(): void
    {
        putenv(EnvConstants::HILOS_LANGUAGE_HOSTS->name . '=de');

        $this->expectNotToPerformAssertions();
        LanguageAddresses::assertConfiguredFor(LanguageAddressesWithoutI18nHilos::class);
    }

    /**
     * @param callable(): mixed $read Read that must refuse the declaration
     * @param string $element Element the refusal must name
     */
    private function assertRefused(callable $read, string $element): void
    {
        try {
            $read();
        } catch (EnvInvalidValueException $exception) {
            $this->assertStringContainsString("'HILOS_LANGUAGE_HOSTS'", $exception->getMessage());
            $this->assertStringContainsString("in '{$element}'", $exception->getMessage());

            return;
        }
        $this->fail("The declaration was not refused at '{$element}'");
    }
}

/**
 * Facade standing in for a project that switches i18n on.
 *
 * Abstract because it carries a declaration and nothing else: the preflight reads the feature list
 * off the class and builds no layer from it.
 */
abstract class LanguageAddressesI18nHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::I18N];
}

/**
 * Facade standing in for a project without i18n; abstract for the same reason.
 */
abstract class LanguageAddressesWithoutI18nHilos extends Hilos
{
}

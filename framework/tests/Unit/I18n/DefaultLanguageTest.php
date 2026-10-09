<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\DefaultLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The daemon preflight, library and write actions share this exact resolver. */
final class DefaultLanguageTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousDefaultLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    public function testKnownCodeResolvesTheCurrentEnvironment(): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->assertSame('en', DefaultLanguage::code());
        $this->assertSame('English', DefaultLanguage::definition()->nativeName);

        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ru');
        $this->assertSame('ru', DefaultLanguage::code());
        $this->assertSame('Русский', DefaultLanguage::definition()->nativeName);
    }

    /** @return iterable<string, array{string}> Invalid codes and the missing value */
    public static function invalidCodes(): iterable
    {
        yield 'missing' => [''];
        yield 'uppercase' => ['EN'];
        yield 'unknown' => ['ez'];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodeNamesTheVariableAndOriginalValue(string $code): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $code);

        $this->expectException(EnvInvalidValueException::class);
        $this->expectExceptionMessage('HILOS_DEFAULT_LANGUAGE');
        $this->expectExceptionMessage("'{$code}'");
        DefaultLanguage::definition();
    }

    public function testAFacadeWithI18nIsRefusedAnUnknownCode(): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ez');

        $this->expectException(EnvInvalidValueException::class);
        $this->expectExceptionMessage('HILOS_DEFAULT_LANGUAGE');
        $this->expectExceptionMessage("'ez'");
        DefaultLanguage::assertConfiguredFor(DefaultLanguageI18nHilos::class);
    }

    public function testAFacadeWithoutI18nIsNotAskedForTheVariable(): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=');

        $this->expectNotToPerformAssertions();
        DefaultLanguage::assertConfiguredFor(DefaultLanguageWithoutI18nHilos::class);
    }
}

/**
 * Facade standing in for a project that switches i18n on.
 *
 * Abstract because it carries a declaration and nothing else: the preflight reads the feature list
 * off the class and builds no layer from it.
 */
abstract class DefaultLanguageI18nHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::I18N];
}

/**
 * Facade standing in for a project without i18n; abstract for the same reason.
 */
abstract class DefaultLanguageWithoutI18nHilos extends Hilos
{
}

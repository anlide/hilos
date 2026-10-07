<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\I18n;

use Hilos\Constants\EnvConstants;
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
}

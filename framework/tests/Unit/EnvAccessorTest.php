<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Environment\Exception\EnvMutationNotSupportedException;
use Hilos\Environment\Exception\EnvNotInCatalogException;
use Hilos\Environment\Exception\EnvTypeMismatchException;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for catalog-backed environment access.
 */
final class EnvAccessorTest extends TestCase
{
    private const string STRING_KEY = 'HILOS_TEST_ENV_STRING';
    private const string INTEGER_KEY = 'HILOS_TEST_ENV_INTEGER';
    private const string FLOAT_KEY = 'HILOS_TEST_ENV_FLOAT';
    private const string BOOLEAN_KEY = 'HILOS_TEST_ENV_BOOLEAN';
    private const string REQUIRED_KEY = 'HILOS_TEST_ENV_REQUIRED';
    private const string EMPTY_ALLOWED_KEY = 'HILOS_TEST_ENV_EMPTY_ALLOWED';

    protected function tearDown(): void
    {
        foreach ([
            self::STRING_KEY,
            self::INTEGER_KEY,
            self::FLOAT_KEY,
            self::BOOLEAN_KEY,
            self::REQUIRED_KEY,
            self::EMPTY_ALLOWED_KEY,
        ] as $key) {
            putenv($key);
        }

        parent::tearDown();
    }

    public function testArrayAccessReturnsStringDefault(): void
    {
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);

        $this->assertSame('fallback', $env[self::STRING_KEY]);
        $this->assertTrue(isset($env[self::STRING_KEY]));
    }

    public function testEmptyIsMissingUsesCatalogDefault(): void
    {
        putenv(self::STRING_KEY . '=');
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback', emptyIsMissing: true),
        ]);

        $this->assertSame('fallback', $env[self::STRING_KEY]);
    }

    public function testEmptyCanBeARealValue(): void
    {
        putenv(self::EMPTY_ALLOWED_KEY . '=');
        $env = $this->env([
            self::EMPTY_ALLOWED_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
        ]);

        $this->assertSame('', $env[self::EMPTY_ALLOWED_KEY]);
    }

    public function testRequiredMissingValueThrows(): void
    {
        $env = $this->env([
            self::REQUIRED_KEY => [
                EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
                EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => true,
            ],
        ]);

        $this->expectException(MissingEnvironmentVariableException::class);

        $env[self::REQUIRED_KEY];
    }

    public function testTypedReadersValidateCatalogType(): void
    {
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7),
        ]);

        $this->expectException(EnvTypeMismatchException::class);

        $env[self::INTEGER_KEY];
    }

    public function testIntegerReaderParsesStrictInteger(): void
    {
        putenv(self::INTEGER_KEY . '=42');
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7, emptyIsMissing: true),
        ]);

        $this->assertSame(42, $env->int(self::INTEGER_KEY));
    }

    public function testIntegerReaderRejectsInvalidValue(): void
    {
        putenv(self::INTEGER_KEY . '=abc');
        $env = $this->env([
            self::INTEGER_KEY => $this->entry(EnvCatalogConstants::TYPE_INTEGER, 7, emptyIsMissing: true),
        ]);

        $this->expectException(EnvInvalidValueException::class);

        $env->int(self::INTEGER_KEY);
    }

    public function testFloatReaderParsesNumericString(): void
    {
        putenv(self::FLOAT_KEY . '=3.5');
        $env = $this->env([
            self::FLOAT_KEY => $this->entry(EnvCatalogConstants::TYPE_FLOAT, 1.0, emptyIsMissing: true),
        ]);

        $this->assertSame(3.5, $env->float(self::FLOAT_KEY));
    }

    public function testBooleanReaderParsesKnownValues(): void
    {
        putenv(self::BOOLEAN_KEY . '=yes');
        $env = $this->env([
            self::BOOLEAN_KEY => $this->entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
        ]);

        $this->assertTrue($env->bool(self::BOOLEAN_KEY));
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(EnvNotInCatalogException::class);

        $this->env([])[self::STRING_KEY];
    }

    public function testMutationIsRejected(): void
    {
        $env = $this->env([
            self::STRING_KEY => $this->entry(EnvCatalogConstants::TYPE_STRING, 'fallback'),
        ]);

        $this->expectException(EnvMutationNotSupportedException::class);

        $env[self::STRING_KEY] = 'changed';
    }

    /**
     * @param array<string, array<string, mixed>> $catalog Env catalog
     */
    private function env(array $catalog): EnvAccessor
    {
        return new class ($catalog) extends EnvAccessor {
            /**
             * @param array<string, array<string, mixed>> $catalog Env catalog
             */
            public function __construct(
                private readonly array $catalog,
            ) {
            }

            protected function getCatalog(): array
            {
                return $this->catalog;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $type, mixed $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
